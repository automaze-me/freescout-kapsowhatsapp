<?php

namespace Modules\KapsoWhatsApp\Jobs;

use App\Conversation;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
use Modules\KapsoWhatsApp\Services\DeliveryFailureLineItem;
use Modules\KapsoWhatsApp\Services\KapsoClient;

/**
 * Stage 3c: delivers one approved-template thread to WhatsApp. The
 * controller (Task 3) creates the thread synchronously with the direct
 * `new Thread` idiom -- provably NOT the `chat_conversation.send_reply`
 * path, so this job is never a double-send of anything that listener also
 * handles -- then dispatches this job to do the actual Kapso call.
 *
 * This is SendReplyMessage's claim/wamid/failure machinery, reused
 * one-for-one for a single new part kind: read that class's docblocks
 * first (the at-least-once contract, the duplicate-key claim/skip-if-
 * accepted protocol, the all-accepted failure gate, the $timeout Laravel
 * 5.5 semantics) -- all of it is binding precedent here and is referenced
 * rather than restated. The one structural difference: a template send is
 * never chunked or captioned the way a reply's body/attachment parts are,
 * so there is exactly one claim row per thread, on
 * `KapsoMessage::PART_TEMPLATE` ('tpl'), and no markReadBestEffort() call
 * -- a template send answers no open window, so there is nothing to mark
 * read.
 */
class SendTemplateMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Meta's own per-parameter text limit for a template component. The
     * controller (Task 3) is the primary validator (fresh template list,
     * variable-count match, non-blank, <= 1024 chars per value) -- this is
     * a second, defensive line of defence inside the job itself, since
     * `dispatch()` is public API any future caller could invoke without
     * going through that controller. Enforced by truncation, not a thrown
     * exception: a job that has already claimed its send-once row should
     * not abandon a legitimate send over an over-long value it can simply
     * cap, the same spirit as SendReplyMessage::TEXT_CHUNK_LIMIT capping
     * rather than rejecting a long reply body.
     */
    const VARIABLE_CHAR_LIMIT = 1024;

    public $threadId;
    public $templateName;
    public $languageCode;
    public $variables;

    public $tries = 3;

    /**
     * See SendReplyMessage::$timeout for the full Laravel 5.5 rationale
     * (Worker::timeoutForJob(), the SIGALRM/worker-kill semantics, and why
     * this must stay below the queue's 90s retry_after window) -- identical
     * reasoning applies verbatim to this job and is not restated here.
     */
    public $timeout = 80;

    /**
     * $variables is the ordered list of `{{n}}` substitutions for the
     * template body, already validated by the controller (name+language
     * exist in a fresh Kapso list, count matches, each non-blank) -- this
     * constructor re-casts every value to a string and re-length-checks it
     * against VARIABLE_CHAR_LIMIT defensively; see that constant's docblock.
     */
    public function __construct($threadId, $templateName, $languageCode, array $variables)
    {
        $this->threadId     = $threadId;
        $this->templateName = $templateName;
        $this->languageCode = $languageCode;
        $this->variables    = array_map([self::class, 'sanitiseVariable'], $variables);
    }

    public function handle()
    {
        $context = $this->guards();

        if (!$context) {
            return;
        }

        ['thread' => $thread, 'conversation' => $conversation, 'latestInbound' => $latestInbound, 'account' => $account] = $context;

        // Same split as SendReplyMessage::handle(): Meta's `to` wants bare
        // international digits, but the claim row's own contact_phone
        // column must stay "+"-prefixed E.164, verbatim from the inbound
        // row, so ReconcileOutboundMessage::resolveConversationId()'s exact
        // match against that column keeps working.
        $contactPhone = (string) $latestInbound->contact_phone;
        $to           = preg_replace('/\D+/', '', $contactPhone);
        $client       = new KapsoClient($account);

        try {
            $this->claimAndSend($client, $account, $conversation, $thread, $to, $contactPhone);
        } catch (KapsoApiException $e) {
            if ($this->attempts() < $this->tries) {
                // Not the final attempt: rethrow so Laravel's queue retries
                // the whole job. The claim row is left `sending` for the
                // retry to re-claim.
                throw $e;
            }

            $this->finalizeFailure($thread, $conversation, $e->getMessage());
        }
    }

    /**
     * Identical guard order and log-level rules to
     * SendReplyMessage::guards() -- see that method's docblock for the
     * rationale behind each check and why races get an info log while
     * persistent conditions get an error log. A template thread always has
     * a prior inbound message (Stage 3c is closed-window replies only, never
     * agent-initiated conversations -- see the spec's "Out of scope"), so
     * requiring one here is not a stricter guard than SendReplyMessage's,
     * just the same one.
     */
    protected function guards()
    {
        $thread = Thread::find($this->threadId);

        if (!$thread) {
            \Log::info('[KapsoWhatsApp] SendTemplateMessage: thread not found, skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        if ((int) $thread->type !== Thread::TYPE_MESSAGE || (int) $thread->state !== Thread::STATE_PUBLISHED) {
            \Log::info('[KapsoWhatsApp] SendTemplateMessage: thread is no longer a published message (undone?), skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        $conversation = Conversation::find($thread->conversation_id);

        if (!$conversation || (int) $conversation->channel !== KapsoAccount::CHANNEL) {
            \Log::info('[KapsoWhatsApp] SendTemplateMessage: conversation missing or not a WhatsApp conversation, skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        $latestInbound = KapsoMessage::where('conversation_id', $conversation->id)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->whereNotNull('contact_phone')
            ->orderByDesc('id')
            ->first();

        if (!$latestInbound) {
            // Persistent, not a race -- see SendReplyMessage::guards()'s
            // identical check.
            \Log::error('[KapsoWhatsApp] SendTemplateMessage: no inbound message found for this conversation, skipping', [
                'thread_id'       => $this->threadId,
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        $account = KapsoAccount::find($latestInbound->account_id);

        if (!$account || !$account->is_active) {
            \Log::error('[KapsoWhatsApp] SendTemplateMessage: account missing or inactive, skipping', [
                'thread_id'       => $this->threadId,
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        return compact('thread', 'conversation', 'latestInbound', 'account');
    }

    protected function buildPayload($to)
    {
        $template = [
            'name'     => $this->templateName,
            'language' => ['code' => $this->languageCode],
        ];

        if (!empty($this->variables)) {
            $template['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(function ($variable) {
                    return ['type' => 'text', 'text' => $variable];
                }, $this->variables),
            ]];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => $template,
        ];
    }

    /**
     * Claims the single `tpl` part row (inserting it, or re-claiming a
     * sending/failed leftover from an earlier attempt) and sends it. Skips
     * outright -- no HTTP call at all -- when the part is already accepted.
     * This is SendReplyMessage::claimAndSend()'s exact protocol, narrowed to
     * one fixed part_key instead of a loop over several; see that method's
     * docblock for why the duplicate-key catch re-queries rather than
     * assuming, and why only send_state/wamid (never the query race itself)
     * decides "already sent".
     */
    protected function claimAndSend(KapsoClient $client, KapsoAccount $account, Conversation $conversation, Thread $thread, $to, $contactPhone)
    {
        $row = new KapsoMessage();
        $row->account_id      = $account->id;
        $row->conversation_id = $conversation->id;
        $row->thread_id       = $thread->id;
        $row->part_key        = KapsoMessage::PART_TEMPLATE;
        $row->direction       = KapsoMessage::DIRECTION_OUTBOUND;
        $row->send_state      = KapsoMessage::SEND_STATE_SENDING;
        // Verbatim -- see the comment in handle() building $contactPhone.
        $row->contact_phone   = $contactPhone;

        try {
            $row->save();
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_TEMPLATE)->first();

            if (!$existing) {
                throw $e;
            }

            if ($existing->wamid || $existing->send_state === KapsoMessage::SEND_STATE_ACCEPTED) {
                // Already sent -- nothing left to do.
                return;
            }

            // A `sending`/`failed` leftover from a previous attempt: re-claim
            // it and try again.
            $row = $existing;
            $row->send_state = KapsoMessage::SEND_STATE_SENDING;
            $row->error       = null;
            $row->save();
        }

        $response = $client->sendWhatsAppMessage($this->buildPayload($to));

        // extractWamid() returns null rather than throwing for a
        // malformed-but-2xx response -- see SendReplyMessage::claimAndSend()'s
        // identical comment for the accepted-but-unmarkable-later residue
        // this leaves.
        $row->wamid      = KapsoClient::extractWamid($response);
        $row->send_state = KapsoMessage::SEND_STATE_ACCEPTED;
        $row->save();
    }

    /**
     * The final attempt has failed: the claim row (if it exists and is not
     * already accepted) is marked failed with the error, the sent marker is
     * cleared, and exactly one DeliveryFailureLineItem is posted. Mirrors
     * SendReplyMessage::finalizeFailure() including its all-accepted gate --
     * see that method's docblock for why "no unaccepted row AND at least one
     * row exists" is the one case that must record and clear nothing (the
     * job died only after Kapso already had the message, e.g. a SIGKILL
     * between claimAndSend()'s final save() and handle() returning).
     */
    protected function finalizeFailure(Thread $thread, Conversation $conversation, string $summary)
    {
        $part = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_TEMPLATE);

        if (!(clone $part)->where('send_state', '<>', KapsoMessage::SEND_STATE_ACCEPTED)->exists()
            && (clone $part)->exists()) {
            \Log::error('[KapsoWhatsApp] SendTemplateMessage: job failed after the template was accepted, nothing recorded', [
                'thread_id' => $thread->id, 'conversation_id' => $conversation->id,
            ]);

            return;
        }

        KapsoMessage::where('thread_id', $thread->id)
            ->where('part_key', KapsoMessage::PART_TEMPLATE)
            ->where('send_state', '<>', KapsoMessage::SEND_STATE_ACCEPTED)
            ->update(['send_state' => KapsoMessage::SEND_STATE_FAILED, 'error' => $summary]);

        // Same invariant as SendReplyMessage::finalizeFailure(): the marker
        // means delivered-and-healthy, so a recorded failure clears it.
        $thread->setMeta(KapsoMessage::THREAD_META_SENT_AT, null);
        $thread->save();

        DeliveryFailureLineItem::create($conversation, $summary);
    }

    /**
     * Safety net for anything handle()'s own try/catch does not itself
     * finalize -- see SendReplyMessage::failed() for the full rationale
     * (only KapsoApiException is caught in handle(); everything else
     * propagates until Laravel's queue worker exhausts $tries and calls this
     * automatically). $e is nullable for the same reason documented there
     * (Laravel's own FailingJob::handle() genuinely defaults it to null).
     */
    public function failed(\Throwable $e = null)
    {
        $thread = Thread::find($this->threadId);

        if (!$thread) {
            return;
        }

        $conversation = Conversation::find($thread->conversation_id);

        if (!$conversation) {
            return;
        }

        \Log::error('[KapsoWhatsApp] SendTemplateMessage: job permanently failed', [
            'thread_id'       => $thread->id,
            'conversation_id' => $conversation->id,
            'exception'       => $e ? get_class($e).': '.$e->getMessage() : 'no exception',
        ]);

        $summary = $e instanceof KapsoApiException
            ? $e->getMessage()
            : __('The template message could not be delivered to WhatsApp. See the log for details.');

        $this->finalizeFailure($thread, $conversation, $summary);
    }

    protected static function sanitiseVariable($variable)
    {
        return mb_substr((string) $variable, 0, self::VARIABLE_CHAR_LIMIT);
    }
}

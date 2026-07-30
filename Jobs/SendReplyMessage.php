<?php

namespace Modules\KapsoWhatsApp\Jobs;

use App\Attachment;
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
 * Delivers one published agent-reply thread to WhatsApp: chunks the body and
 * attaches each attachment as its own "part", claims each part with a
 * send-once DB row, POSTs it through Kapso's Meta proxy, and records the
 * wamid Kapso hands back. Runs after core's undo window (core's own delay --
 * \Helper::backgroundAction() schedules the whole action; Listeners\
 * SendReplyToWhatsApp dispatches this job immediately, adding no delay of
 * its own), so it is handed a thread **id**, not a model -- the thread must
 * be re-fetched fresh, and may by then have been undone (back to
 * STATE_DRAFT) or otherwise no longer be eligible to send. That is a
 * legitimate race, not an error: guards() bails silently (an info log, not
 * a warning/error) whenever any of them fail.
 *
 * Claim semantics (see claimAndSend()): each part is identified by a unique
 * (thread_id, part_key) row. A duplicate-key insert means either "already
 * sent" (skip) or "a sending/failed leftover from an earlier attempt of this
 * same reply" (re-claim and retry). This is at-least-once, not
 * exactly-once: if the process crashes after Kapso accepts the HTTP request
 * but before this job writes the wamid back, a retry will re-send that one
 * part -- there is no way to distinguish "Kapso never got it" from "Kapso
 * got it but we died before recording that". This is accepted, documented
 * behaviour (see the plan's Global Constraints), not a bug to "fix" by
 * weakening the claim.
 */
class SendReplyMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * WhatsApp text messages cap at 4096 chars; chunking at 4000 leaves
     * headroom rather than relying on the exact limit.
     */
    const TEXT_CHUNK_LIMIT = 4000;

    public $threadId;
    public $tries = 3;

    /**
     * Seconds Laravel allows one execution of handle() to run before it acts
     * on this job specifically. `Worker::timeoutForJob()` returns *this*
     * property INSTEAD OF the worker's own `--timeout` option whenever a job
     * declares one -- one `pcntl_alarm()`, never two, so there is no race
     * between an inner and an outer timeout to reason about. (Do not cite
     * docker-compose.yml here: that is this repo's private dev harness, not
     * how a real install runs workers -- core launches them via its own
     * scheduler with `--timeout=1800`, config/app.php:196, which this
     * property replaces for this job specifically.)
     *
     * What actually happens on SIGALRM, in Laravel 5.5: the worker's signal
     * handler calls `$this->kill(1)`, i.e. `exit(1)` -- the WHOLE worker
     * process dies immediately. Nothing is marked failed and failed() is NOT
     * called at that moment; every part this job had claimed is left exactly
     * as it stood. Recovery instead happens later, passively: the reserved
     * job's `retry_after` window (90s, config/queue.php) eventually expires,
     * and the *next* worker to pop the queue runs
     * `markJobAsFailedIfAlreadyExceedsMaxAttempts()`, which is what finally
     * calls failed() for it.
     *
     * That recovery path is also why this value must stay *below*
     * retry_after (90), not merely below the outer worker timeout: 80 < 90
     * restores Laravel's own intended ordering -- this job's alarm fires,
     * and is accounted for, before the reservation it was holding would
     * otherwise have expired on its own. (Running more than one worker isn't
     * a supported FreeScout configuration in the first place -- core's own
     * Kernel.php actively kills extra worker processes -- so the
     * single-worker case is the only one this needs to hold for.)
     */
    public $timeout = 80;

    public function __construct($threadId)
    {
        $this->threadId = $threadId;
    }

    public function handle()
    {
        $context = $this->guards();

        if (!$context) {
            return;
        }

        ['thread' => $thread, 'conversation' => $conversation, 'latestInbound' => $latestInbound, 'account' => $account] = $context;

        // Kapso's Meta proxy wants bare international digits for `to`, but
        // the row's own `contact_phone` column must stay in the same format
        // ProcessInboundMessage/ReconcileOutboundMessage write everywhere
        // else -- "+" followed by digits (PhoneNumber::toE164()) -- because
        // ReconcileOutboundMessage::resolveConversationId() exact-matches
        // that column against a freshly-computed E.164 value. Stripping the
        // "+" for the stored column, as an earlier version of this job did,
        // silently orphaned every future reconciliation lookup for these
        // rows while leaving all tests green. The digit-stripping is kept
        // local to $to (the payload value); $latestInbound->contact_phone is
        // carried through verbatim for the row.
        $contactPhone = (string) $latestInbound->contact_phone;
        $to           = preg_replace('/\D+/', '', $contactPhone);
        $client       = new KapsoClient($account);

        foreach ($this->parts($thread) as $part) {
            try {
                $this->claimAndSend($client, $account, $conversation, $thread, $part, $to, $contactPhone);
            } catch (KapsoApiException $e) {
                if ($this->attempts() < $this->tries) {
                    // Not the final attempt: rethrow so Laravel's queue
                    // retries the whole job. Parts already accepted above
                    // are skipped on the re-run via their recorded wamid;
                    // the part that just failed is left `sending` for the
                    // retry to pick back up.
                    throw $e;
                }

                $this->finalizeFailure($thread, $conversation, $e->getMessage());

                return;
            }
        }

        $this->markReadBestEffort($client, $latestInbound);
    }

    /**
     * Guards, in the order the design demands: thread exists; it is still a
     * published message (not undone back to draft, not some other thread
     * type); its conversation exists and is on the WhatsApp channel; and an
     * active KapsoAccount can be found via the conversation's latest inbound
     * message (the account that actually received it, not merely "some
     * account configured for this mailbox"). Any failure here is logged at
     * info level and the job quietly does nothing -- these are races
     * against the undo window and against admin account changes, not bugs.
     */
    protected function guards()
    {
        $thread = Thread::find($this->threadId);

        if (!$thread) {
            \Log::info('[KapsoWhatsApp] SendReplyMessage: thread not found, skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        if ((int) $thread->type !== Thread::TYPE_MESSAGE || (int) $thread->state !== Thread::STATE_PUBLISHED) {
            \Log::info('[KapsoWhatsApp] SendReplyMessage: thread is no longer a published message (undone?), skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        $conversation = Conversation::find($thread->conversation_id);

        if (!$conversation || (int) $conversation->channel !== KapsoAccount::CHANNEL) {
            \Log::info('[KapsoWhatsApp] SendReplyMessage: conversation missing or not a WhatsApp conversation, skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        $latestInbound = KapsoMessage::where('conversation_id', $conversation->id)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->whereNotNull('contact_phone')
            ->orderByDesc('id')
            ->first();

        if (!$latestInbound) {
            // Unlike the two guards above (legitimate races against the undo
            // window / a conversation that simply isn't ours), this is a
            // persistent condition -- a published reply with no inbound
            // message to answer will never resolve itself on retry -- so it
            // is logged at error, not info, and always names the
            // conversation.
            \Log::error('[KapsoWhatsApp] SendReplyMessage: no inbound message found for this conversation, skipping', [
                'thread_id'       => $this->threadId,
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        $account = KapsoAccount::find($latestInbound->account_id);

        if (!$account || !$account->is_active) {
            // Persistent, not a race: an account that is missing/inactive
            // now will still be missing/inactive on the next retry. Logged
            // at error (the default log_level is `error`, which would
            // otherwise discard this silently) with the conversation id so
            // it is actually actionable.
            \Log::error('[KapsoWhatsApp] SendReplyMessage: account missing or inactive, skipping', [
                'thread_id'       => $this->threadId,
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        return compact('thread', 'conversation', 'latestInbound', 'account');
    }

    /**
     * The list of parts this reply breaks down into: zero or more text
     * chunks (skipped entirely for an empty body -- e.g. an attachment-only
     * reply), then one part per attachment, in thread order.
     */
    protected function parts(Thread $thread)
    {
        $parts = [];

        // Helper::htmlToText() passes its argument straight into
        // str_ireplace()/Html2Text, both of which reject null: an
        // attachment-only reply with no body text at all leaves
        // $thread->body NULL (not ''), and PHP 8.1+ deprecates passing null
        // into an internal function's non-nullable string parameter -- this
        // app escalates deprecations to a fatal ErrorException, which would
        // otherwise burn every retry and leave the reply's parts stuck
        // `sending` with nothing ever recorded. Casting keeps a null body
        // exactly equivalent to an empty one (no body parts, attachments
        // still sent).
        $text = trim(\Helper::htmlToText((string) $thread->body));

        if ($text !== '') {
            foreach ($this->chunkText($text) as $i => $chunk) {
                $parts[] = [
                    'part_key'      => KapsoMessage::partKeyForBodyChunk($i),
                    'attachment_id' => null,
                    'type'          => 'text',
                    'body'          => $chunk,
                ];
            }
        }

        foreach ($thread->attachments as $attachment) {
            $isImage = str_starts_with((string) $attachment->mime_type, 'image/');

            $parts[] = [
                'part_key'      => KapsoMessage::partKeyForAttachment($attachment->id),
                'attachment_id' => $attachment->id,
                'type'          => $isImage ? 'image' : 'document',
                'link'          => $this->attachmentLink($attachment),
                'filename'      => $attachment->file_name,
            ];
        }

        return $parts;
    }

    protected function chunkText($text)
    {
        return mb_str_split($text, self::TEXT_CHUNK_LIMIT);
    }

    /**
     * Attachment::url() enforces the download token (HMAC-SHA256, checked
     * with hash_equals in OpenController::download()) but returns a
     * root-relative path -- absolute-prefixing it here is the only change
     * made to it, so the token and its query string reach Kapso unaltered.
     */
    protected function attachmentLink(Attachment $attachment)
    {
        $url = $attachment->url();

        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim(config('app.url'), '/').$url;
        }

        return $url;
    }

    protected function buildPayload($to, array $part)
    {
        if ($part['type'] === 'text') {
            return [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => $part['body']],
            ];
        }

        if ($part['type'] === 'image') {
            return [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'image',
                'image'             => ['link' => $part['link']],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'document',
            'document'          => ['link' => $part['link'], 'filename' => $part['filename']],
        ];
    }

    /**
     * Claims one part's row (inserting it, or re-claiming a sending/failed
     * leftover from an earlier attempt of this same reply) and sends it.
     * Skips outright -- no HTTP call at all -- when the part is already
     * accepted: this is what makes a retried or re-run job safe.
     *
     * The duplicate-key catch mirrors the module's existing convention
     * (ProcessInboundMessage, ReconcileOutboundMessage): catch broadly, then
     * re-check by querying; only treat it as "someone else claimed this
     * part" if that query actually finds a row, otherwise rethrow -- so an
     * unrelated DB failure is never silently swallowed as a claim race.
     */
    protected function claimAndSend(KapsoClient $client, KapsoAccount $account, Conversation $conversation, Thread $thread, array $part, $to, $contactPhone)
    {
        $row = new KapsoMessage();
        $row->account_id      = $account->id;
        $row->conversation_id = $conversation->id;
        $row->thread_id       = $thread->id;
        $row->part_key        = $part['part_key'];
        $row->direction       = KapsoMessage::DIRECTION_OUTBOUND;
        $row->send_state      = KapsoMessage::SEND_STATE_SENDING;
        // Verbatim -- see the comment in handle() building $contactPhone:
        // this column's format must match every other writer's (the "+"
        // prefixed E.164 value), not the bare digits Meta's API wants.
        $row->contact_phone   = $contactPhone;

        if ($part['attachment_id']) {
            $row->attachment_id = $part['attachment_id'];
        }

        try {
            $row->save();
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = KapsoMessage::where('thread_id', $thread->id)->where('part_key', $part['part_key'])->first();

            if (!$existing) {
                throw $e;
            }

            if ($existing->wamid || $existing->send_state === KapsoMessage::SEND_STATE_ACCEPTED) {
                // Already sent, by this job's own earlier attempt or a
                // previous run entirely -- nothing left to do for this part.
                return;
            }

            // A `sending`/`failed` leftover from a previous attempt of this
            // same reply: re-claim it and try again.
            $row = $existing;
            $row->send_state = KapsoMessage::SEND_STATE_SENDING;
            $row->error       = null;
            $row->save();
        }

        $response = $client->sendWhatsAppMessage($this->buildPayload($to, $part));

        // extractWamid() returns null, rather than throwing, for a
        // malformed-but-2xx response (no `messages[0].id`): the part still
        // lands here `accepted` with `wamid` NULL. Safe -- no re-send is
        // triggered (claimAndSend()'s skip check above is keyed on
        // send_state, not on wamid being present), and the unique index on
        // `wamid` allows multiple NULLs -- but that one reply can then never
        // receive the sent marker (ReconcileOutboundMessage::markOwnSendSent()
        // only ever matches on wamid) nor a webhook-reported failure for the
        // same reason.
        $row->wamid      = KapsoClient::extractWamid($response);
        $row->send_state = KapsoMessage::SEND_STATE_ACCEPTED;
        $row->save();
    }

    /**
     * The final attempt has failed: every part this job has claimed for
     * this thread that never made it to `accepted` (the one that just threw,
     * plus any earlier `sending`/`failed` leftover it did not get back to)
     * is marked failed with the same error, and exactly one line item is
     * posted -- not one per part, since the agent needs one visible failure
     * per reply, not one per chunk/attachment. Parts never even claimed
     * (later in the list, never reached because the loop stopped here) are
     * left with no row at all; a genuinely new send attempt is what would
     * create them, not this cleanup.
     *
     * "Exactly one" holds per *execution* of this method, not per reply.
     * Unlike ReconcileOutboundMessage::applyFailureToRow() (an atomic
     * `UPDATE ... WHERE status IS NULL OR status <> 'failed'` claim that lets
     * only one concurrent/duplicate delivery post a line item), nothing here
     * stops a second dispatch of an already-exhausted reply: claimAndSend()
     * happily re-claims rows sitting in `failed` (that is the whole point of
     * its re-claim branch, for the legitimate case of a fresh retry after an
     * earlier crash), and if that second run also exhausts its retries, this
     * method runs again and posts a second, textually identical line item.
     * Deliberately not hardened for Stage 3a: nothing in this job re-dispatches
     * an already-exhausted thread on its own, so today this is only reachable
     * via an operator manually re-queuing the same reply -- a narrower,
     * accepted gap, not the same "no guard at all" story as the wamid-crash
     * window documented on the class above.
     *
     * $summary is a plain, already-safe-to-show-an-agent string, not an
     * exception -- see the two call sites (handle()'s catch and failed())
     * for how each derives one from what actually failed.
     */
    protected function finalizeFailure(Thread $thread, Conversation $conversation, string $summary)
    {
        $parts = KapsoMessage::where('thread_id', $thread->id)->whereNotNull('part_key');

        if (!(clone $parts)->where('send_state', '<>', KapsoMessage::SEND_STATE_ACCEPTED)->exists()
            && (clone $parts)->exists()) {
            // Every part accepted, nothing left unsent: whatever killed this
            // job happened after the reply was fully delivered (e.g. SIGKILL
            // during the best-effort mark-read, or mid-list timeouts that
            // still made forward progress each attempt). Recording a
            // failure -- or clearing the marker -- would be the one thing
            // this module must never do: lie about what the customer got.
            \Log::error('[KapsoWhatsApp] SendReplyMessage: job failed after every part was accepted, nothing recorded', [
                'thread_id' => $thread->id, 'conversation_id' => $conversation->id,
            ]);

            return;
        }

        KapsoMessage::where('thread_id', $thread->id)
            ->whereNotNull('part_key')
            ->where('send_state', '<>', KapsoMessage::SEND_STATE_ACCEPTED)
            ->update(['send_state' => KapsoMessage::SEND_STATE_FAILED, 'error' => $summary]);

        // Same invariant as ReconcileOutboundMessage::applyFailureToRow():
        // the marker means delivered-and-healthy, so a recorded failure
        // clears it. A `sent` webhook for an earlier, accepted part may
        // already have stamped it between this job's attempts (a released
        // retry queues *behind* that webhook's job).
        $thread->setMeta(KapsoMessage::THREAD_META_SENT_AT, null);
        $thread->save();

        DeliveryFailureLineItem::create($conversation, $summary);
    }

    /**
     * The safety net for everything the try/catch in handle() does not
     * itself finalize. That catch only recognises KapsoApiException, and on
     * its own last attempt it finalizes gracefully and *returns* rather than
     * rethrowing -- precisely so Laravel never marks that job permanently
     * failed and this method is never invoked for it (see the catch's own
     * comment). Anything else that escapes -- a different exception class
     * entirely (a stray ErrorException, a DB hiccup, ...) -- is not caught
     * there at all, propagates out of handle() on every attempt, and once
     * Laravel's queue worker has exhausted $tries retries on it, the worker
     * calls this method automatically. Without it, that class of failure
     * left every claimed part stuck `sending` forever with no red line item
     * and nothing but a log line (if that) to show for it. Loads the
     * thread/conversation fresh, the same as guards() does, since a real
     * queue failure calls this well after handle()'s own local variables are
     * gone; bails quietly if either is gone by now; otherwise reuses
     * finalizeFailure() rather than duplicating its update-and-line-item
     * logic.
     *
     * $e is nullable: Laravel's own `FailingJob::handle()` genuinely defaults
     * it to null (see vendor/laravel/framework/src/Illuminate/Queue/
     * FailingJob.php), and `Job::fail()` is public API a caller could invoke
     * the same way, so this method must survive being called with nothing at
     * all. Only a KapsoApiException's message is safe to show an agent
     * verbatim (KapsoClient already sanitises and translates it); anything
     * else -- most notably the timeout/SIGKILL class, which reaches here as a
     * raw `MaxAttemptsExceededException` whose message is an untranslated FQCN
     * sentence -- is replaced with a generic, translated summary. The real
     * exception (or its absence) is still logged in full, at error, before
     * delegating, so nothing is actually lost -- it just never reaches the
     * agent-visible line item verbatim.
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

        \Log::error('[KapsoWhatsApp] SendReplyMessage: job permanently failed', [
            'thread_id'       => $thread->id,
            'conversation_id' => $conversation->id,
            'exception'       => $e ? get_class($e).': '.$e->getMessage() : 'no exception',
        ]);

        $summary = $e instanceof KapsoApiException
            ? $e->getMessage()
            : __('The reply could not be delivered to WhatsApp. See the log for details.');

        $this->finalizeFailure($thread, $conversation, $summary);
    }

    /**
     * Blue ticks for the customer -- best-effort only. A failure here must
     * never fail the job: the reply itself was already accepted by Kapso: it
     * has been sent either way, whether or not the read receipt for the
     * customer's own last message went through.
     */
    protected function markReadBestEffort(KapsoClient $client, KapsoMessage $latestInbound)
    {
        if (!$latestInbound->wamid) {
            return;
        }

        try {
            $client->markMessageRead($latestInbound->wamid);
        } catch (\Throwable $e) {
            \Log::warning('[KapsoWhatsApp] SendReplyMessage: mark-read failed', [
                'wamid' => $latestInbound->wamid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

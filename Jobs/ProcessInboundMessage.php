<?php

namespace Modules\KapsoWhatsApp\Jobs;

use App\Conversation;
use App\Events\CustomerCreatedConversation;
use App\Events\CustomerReplied;
use App\Mailbox;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Services\ConversationResolver;
use Modules\KapsoWhatsApp\Services\CustomerResolver;
use Modules\KapsoWhatsApp\Services\PhoneNumber;

class ProcessInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $accountId;
    public $payload;
    public $tries = 3;

    public function __construct($accountId, array $payload)
    {
        $this->accountId = $accountId;
        $this->payload   = $payload;
    }

    public function handle()
    {
        $account = KapsoAccount::find($this->accountId);

        if (!$account) {
            \Log::warning('[KapsoWhatsApp] Inbound job for missing account '.$this->accountId);

            return;
        }

        $message = $this->payload['message'] ?? [];
        $wamid   = $message['id'] ?? null;

        if (!$wamid) {
            return;
        }

        $existing = KapsoMessage::where('wamid', $wamid)->first();

        if ($existing) {
            // Either a genuine duplicate delivery (events already dispatched,
            // dispatchPendingEvents() below is then a no-op) or a retry of a
            // previous attempt that committed the conversation/thread/dedupe
            // row but crashed or threw before the events fired. Recover
            // rather than silently dropping CustomerCreatedConversation /
            // CustomerReplied for a message that never got them.
            $this->dispatchPendingEvents($existing);

            return;
        }

        $e164 = PhoneNumber::toE164($message['from'] ?? null);

        if (!$e164) {
            \Log::warning('[KapsoWhatsApp] Inbound message without a usable sender number', ['wamid' => $wamid]);

            return;
        }

        $mailbox = Mailbox::find($account->mailbox_id);

        if (!$mailbox) {
            \Log::error('[KapsoWhatsApp] Account '.$account->id.' points at missing mailbox '.$account->mailbox_id);

            return;
        }

        $contactName = $this->payload['conversation']['kapso']['contact_name']
            ?? ($this->payload['conversation']['contact_name'] ?? null);

        $customer = (new CustomerResolver())->resolve($e164, $contactName);

        $body = $this->body($message);

        $conversation = null;
        $thread       = null;
        $kapsoMessage = null;

        try {
            \DB::transaction(function () use (
                $account, $mailbox, $customer, $message, $wamid, $body, $e164,
                &$conversation, &$thread, &$kapsoMessage
            ) {
                $resolved     = (new ConversationResolver())->resolve($customer, $mailbox, Conversation::subjectFromText($body['raw']));
                $conversation = $resolved['conversation'];
                $isNew        = $resolved['is_new'];

                $thread = new Thread();
                $thread->conversation_id = $conversation->id;
                $thread->user_id         = $conversation->user_id;
                $thread->type            = Thread::TYPE_CUSTOMER;
                $thread->status          = $conversation->status;
                $thread->state           = Thread::STATE_PUBLISHED;
                $thread->body            = $body['html'];
                $thread->source_via      = Thread::PERSON_CUSTOMER;
                $thread->source_type     = Thread::SOURCE_TYPE_API;
                $thread->customer_id     = $customer->id;
                $thread->created_by_customer_id = $customer->id;
                if ($isNew) {
                    $thread->first = true;
                }
                $thread->save();

                // The unique index on `wamid` is the real dedupe guard: if a
                // concurrent job for the same message committed between our
                // lookup above and here, this throws and the whole
                // transaction — including the thread just created above —
                // rolls back, so no orphan duplicate thread is left behind.
                $kapsoMessage = KapsoMessage::create([
                    'account_id'            => $account->id,
                    'conversation_id'       => $conversation->id,
                    'thread_id'             => $thread->id,
                    'wamid'                 => $wamid,
                    'kapso_conversation_id' => $this->payload['conversation']['id'] ?? null,
                    'direction'             => KapsoMessage::DIRECTION_INBOUND,
                    'status'                => $message['kapso']['status'] ?? 'received',
                    'contact_phone'         => $e164,
                ]);

                // Match core's ordering (Thread.php:1170/1227, FetchEmails.php:1246-1247/1318):
                // last_reply_at, last_reply_from and the preview are set on the
                // conversation *before* the Eventy filter runs, so a filter
                // inspecting those fields sees the up-to-date values instead of
                // stale ones. setPreview() takes the raw text, not the
                // HTML-escaped body — Helper::textPreview() strips tags but never
                // decodes entities, so an escaped body would leave literal
                // "&amp;"/"&#039;" in the conversation list.
                $conversation->last_reply_at   = now();
                $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
                $conversation->setPreview($body['raw']);

                $conversation = \Eventy::filter(
                    $isNew ? 'conversation.created_by_customer' : 'conversation.customer_replied',
                    $conversation, $thread, $customer
                );

                $conversation->save();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $raced = KapsoMessage::where('wamid', $wamid)->first();

            if (!$raced) {
                throw $e;
            }

            // A concurrent job for the same wamid committed first; our
            // speculative conversation/thread work above was rolled back
            // with the transaction. Defer to that job's row and make sure
            // its events still get dispatched even if it never got the
            // chance to.
            $this->dispatchPendingEvents($raced);

            return;
        }

        $mailbox->updateFoldersCounters();

        // Fired after the transaction commits: a listener throwing here must
        // not roll back the message that was already safely persisted.
        $this->dispatchPendingEvents($kapsoMessage);
    }

    /**
     * Fires the core Laravel events and Eventy hooks at most once per
     * message, even across retries and concurrent workers.
     * `events_dispatched_at` is inbound-only and is claimed atomically: the
     * `UPDATE ... WHERE events_dispatched_at IS NULL` below is the only place
     * the column is written, and it is a compare-and-set — only the worker
     * whose UPDATE actually matches a row may dispatch. This closes two
     * duplicate-fire paths a plain read-then-write would leave open: (1) the
     * race-recovery `catch` block calling this for a message whose *winning*
     * job has committed but not yet reached its own dispatch call (marker
     * still NULL at that moment), and (2) a throwing listener causing the
     * job to retry — without the atomic claim, a NULL marker would look
     * identical to "never attempted" on every retry and re-fire everything
     * each time. A row that already has the marker set (genuine duplicate
     * delivery, or already claimed by another worker) makes the UPDATE
     * affect 0 rows and this is a no-op. The guarantee is at-most-once, not
     * exactly-once: a worker that dies between the claim committing and the
     * event() calls below returning loses the events permanently, since
     * nothing ever re-drives a row whose marker is already set. That is the
     * deliberate trade for never double-firing notifications.
     */
    protected function dispatchPendingEvents(KapsoMessage $kapsoMessage)
    {
        // events_dispatched_at is inbound-only: `kapso_whatsapp_messages` also
        // holds outbound rows (written by ReconcileOutboundMessage), whose
        // marker is always NULL. Without this guard, a future caller could
        // fire CustomerReplied for an agent-sent message.
        if ($kapsoMessage->direction !== KapsoMessage::DIRECTION_INBOUND) {
            return;
        }

        // Resolved before the claim so that a missing thread/conversation
        // bails out without ever marking the row as dispatched — leaving it
        // eligible for a future retry to try again, instead of permanently
        // losing the events. Only the claim itself, and the event dispatch
        // it guards, should sit inside the unrecoverable exposure window.
        $thread       = $kapsoMessage->thread_id ? Thread::find($kapsoMessage->thread_id) : null;
        $conversation = $thread ? $thread->conversation : null;

        if (!$thread || !$conversation) {
            return;
        }

        $customer = $conversation->customer;

        $claimed = KapsoMessage::where('id', $kapsoMessage->id)
            ->whereNull('events_dispatched_at')
            ->update(['events_dispatched_at' => now()]);

        if (!$claimed) {
            return; // another worker already owns these events
        }

        // Inbound over a webhook bypasses the mail-fetch pipeline, so
        // nothing else raises these. Without them, notifications, workflows
        // and auto-replies silently never run. The claim above has already
        // been committed, so a throwing listener (FreeScout's own listeners
        // for these events are synchronous) must not be allowed to fail this
        // job and drive a retry that re-enters and re-fires everything.
        try {
            if ($thread->first) {
                event(new CustomerCreatedConversation($conversation, $thread));
                \Eventy::action('conversation.created_by_customer', $conversation, $thread, $customer);
            } else {
                event(new CustomerReplied($conversation, $thread));
                \Eventy::action('conversation.customer_replied', $conversation, $thread, $customer);
            }
        } catch (\Throwable $e) {
            \Log::error('[KapsoWhatsApp] A listener threw while dispatching events for message id '.$kapsoMessage->id, [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Prefer the typed text; fall back to Kapso's rendered representation so
     * unsupported types (location, contacts, interactive) still carry
     * content. Returns both the raw text and the HTML-escaped body:
     * `Conversation::subjectFromText()` and `Conversation::setPreview()` only
     * strip tags — they never decode HTML entities — so building the subject
     * or preview from an already-escaped body would leave literal
     * "&amp;"/"&#039;" in conversation subjects, previews and email
     * notification headers. The thread body, on the other hand, must stay
     * escaped: the text is attacker-controlled and is rendered unescaped in
     * the conversation view.
     */
    protected function body(array $message)
    {
        $raw = $this->rawText($message);

        return [
            'raw'  => $raw,
            'html' => nl2br(e($raw, true)),
        ];
    }

    protected function rawText(array $message)
    {
        $text = $message['text']['body'] ?? null;

        if (is_scalar($text) && trim((string) $text) !== '') {
            return (string) $text;
        }

        $content = $message['kapso']['content'] ?? null;

        if (is_scalar($content) && trim((string) $content) !== '') {
            return (string) $content;
        }

        $type = $message['type'] ?? null;
        $type = is_scalar($type) ? (string) $type : 'unknown';

        return __('WhatsApp message: :type', ['type' => $type]);
    }
}

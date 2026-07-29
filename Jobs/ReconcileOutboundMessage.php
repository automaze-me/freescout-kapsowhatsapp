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
use Modules\KapsoWhatsApp\Services\PhoneNumber;

/**
 * `whatsapp.message.sent` and `whatsapp.message.failed` are deliberately NOT
 * deduped on wamid by WebhookController (unlike `whatsapp.message.received`):
 * a send and its later failure share one wamid, and deduping on it would
 * swallow the failure after the send was recorded. That means two concurrent
 * deliveries of the *same* event can both reach this job — the controller's
 * `X-Idempotency-Key` cache check is a has/put pair with a window between
 * them, not a lock. Each branch below therefore carries its own idempotency
 * guard rather than relying on a read-then-write check:
 *
 * - `recordForeignSend()` relies on the unique index on
 *   `kapso_whatsapp_messages.wamid`, the same way ProcessInboundMessage does:
 *   the thread and the dedupe row are written in one transaction, and a
 *   unique-key violation rolls the whole thing back.
 * - `recordFailure()` uses an atomic `UPDATE ... WHERE status <> 'failed'`
 *   claim (mirroring `events_dispatched_at` in ProcessInboundMessage): only
 *   the delivery that actually flips the status gets to create the line
 *   item, since a failed send's `wamid` already exists and can't be reused
 *   as an insert-time dedupe guard the way a fresh row can.
 */
class ReconcileOutboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $accountId;
    public $event;
    public $payload;
    public $tries = 3;

    public function __construct($accountId, $event, array $payload)
    {
        $this->accountId = $accountId;
        $this->event     = $event;
        $this->payload   = $payload;
    }

    public function handle()
    {
        $account = KapsoAccount::find($this->accountId);

        if (!$account) {
            return;
        }

        $message = $this->payload['message'] ?? [];
        $wamid   = $message['id'] ?? null;

        if (!$wamid) {
            return;
        }

        // A fast-path read, not the correctness guard: this only saves the
        // common case its own transaction. The actual guarantee against a
        // concurrent duplicate delivery lives in recordForeignSend()'s
        // unique-index catch and recordFailure()'s atomic claim below.
        $known = KapsoMessage::where('wamid', $wamid)->first();

        if ($this->event === 'whatsapp.message.failed') {
            $this->recordFailure($known, $message);

            return;
        }

        // whatsapp.message.sent
        if ($known) {
            // Our own send, or one already reconciled. Nothing to do.
            return;
        }

        $this->recordForeignSend($account, $message, $wamid);
    }

    /**
     * A send we did not make: during the parallel run this is an agent replying
     * from the n8n bridge, or someone using Kapso's own inbox. Recording it
     * keeps FreeScout's history complete instead of showing a one-sided thread.
     */
    protected function recordForeignSend(KapsoAccount $account, array $message, $wamid)
    {
        $e164 = PhoneNumber::toE164($message['to'] ?? null);

        if (!$e164) {
            return;
        }

        $conversationId = KapsoMessage::where('contact_phone', $e164)
            ->where('account_id', $account->id)
            ->whereNotNull('conversation_id')
            ->orderBy('id', 'desc')
            ->value('conversation_id');

        if (!$conversationId) {
            \Log::info('[KapsoWhatsApp] Outbound event for an unknown conversation, dropped', ['wamid' => $wamid]);

            return;
        }

        $conversation = Conversation::find($conversationId);

        if (!$conversation) {
            return;
        }

        $body = $message['text']['body'] ?? ($message['kapso']['content'] ?? '');

        try {
            \DB::transaction(function () use ($account, $conversation, $message, $wamid, $body, $e164) {
                $thread = new Thread();
                $thread->conversation_id = $conversation->id;
                $thread->user_id         = null;
                $thread->type            = Thread::TYPE_MESSAGE;
                $thread->status          = Thread::STATUS_ACTIVE;
                $thread->state           = Thread::STATE_PUBLISHED;
                // Always our own escaped text, never a copy of an
                // agent-composed body: this method creates a brand-new
                // Thread for every foreign send and never attaches a wamid
                // to a pre-existing one. That is what keeps
                // ProcessInboundMessage::applyReaction()'s preg_replace()
                // safe once outbound rows exist — every thread it can ever
                // resolve via KapsoMessage::threadForWamid() was created
                // here or by ProcessInboundMessage itself, both always with
                // escaped HTML, never the rich WYSIWYG output of FreeScout's
                // own reply editor. See the Task 9 report for the full
                // trace of why no other path can set a wamid's `thread_id`.
                $thread->body            = nl2br(e($body))
                    .'<p><em>'.__('Sent outside FreeScout').'</em></p>';
                $thread->source_via      = Thread::PERSON_USER;
                $thread->source_type     = Thread::SOURCE_TYPE_API;
                $thread->customer_id     = $conversation->customer_id;
                $thread->save();

                // The unique index on `wamid` is the real dedupe guard: if a
                // concurrent delivery of this same `sent` event committed
                // between the `$known` lookup in handle() and here, this
                // throws and the whole transaction — including the thread
                // just created above — rolls back, so no orphan duplicate
                // thread is left behind.
                KapsoMessage::create([
                    'account_id'            => $account->id,
                    'conversation_id'       => $conversation->id,
                    'thread_id'             => $thread->id,
                    'wamid'                 => $wamid,
                    'kapso_conversation_id' => $this->payload['conversation']['id'] ?? null,
                    'direction'             => KapsoMessage::DIRECTION_OUTBOUND,
                    'status'                => $message['kapso']['status'] ?? 'sent',
                    'is_reaction'           => false,
                    'contact_phone'         => $e164,
                ]);

                $conversation->last_reply_at   = now();
                $conversation->last_reply_from = Conversation::PERSON_USER;
                $conversation->setPreview($body);
                $conversation->save();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (!KapsoMessage::where('wamid', $wamid)->exists()) {
                throw $e;
            }

            // A concurrent delivery of this same `sent` event committed
            // first; our speculative thread/conversation update above was
            // rolled back with the transaction. The winning delivery already
            // recorded it, so there is nothing left for this attempt to do.
        }
    }

    /**
     * Delivery failures arrive asynchronously. A silently dropped reply is the
     * worst outcome for a helpdesk, so this is surfaced on the conversation.
     */
    protected function recordFailure($known, array $message)
    {
        if (!$known) {
            return;
        }

        $errors = $message['kapso']['statuses'][0]['errors'] ?? [];
        $parts  = [];

        foreach ($errors as $error) {
            $parts[] = trim(($error['code'] ?? '').' '.($error['title'] ?? '').' — '.($error['message'] ?? ''));
        }

        $summary = $parts ? implode('; ', $parts) : __('Delivery failed');

        // Atomic claim, the same idea as `events_dispatched_at` in
        // ProcessInboundMessage: unlike recordForeignSend() above, this row
        // already exists (it was written when the send itself was
        // reconciled), so a fresh unique-key insert cannot serve as the
        // dedupe guard here. Instead, only the delivery whose UPDATE
        // actually flips the status away from "not failed" gets to create
        // the line item below; a second concurrent/duplicate delivery of the
        // same failure event finds status already 'failed' and this matches
        // zero rows. MySQL/MariaDB's row-level locking on UPDATE serialises
        // concurrent attempts and always evaluates the WHERE clause against
        // the latest committed data, so this is safe even when two workers
        // race here at the same instant.
        $claimed = KapsoMessage::where('id', $known->id)
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '<>', 'failed');
            })
            ->update(['status' => 'failed', 'error' => $summary]);

        if (!$claimed) {
            return;
        }

        if (!$known->conversation_id) {
            return;
        }

        $conversation = Conversation::find($known->conversation_id);

        if (!$conversation) {
            return;
        }

        $lineItem = new Thread();
        $lineItem->conversation_id = $conversation->id;
        $lineItem->user_id         = null;
        $lineItem->type            = Thread::TYPE_LINEITEM;
        $lineItem->status          = Thread::STATUS_NOCHANGE;
        $lineItem->state           = Thread::STATE_PUBLISHED;
        $lineItem->body            = __('WhatsApp delivery failed:').' '.e($summary);
        // Core defines only PERSON_CUSTOMER and PERSON_USER — there is no
        // PERSON_SYSTEM. A system-generated line item is attributed to the user side.
        $lineItem->source_via      = Thread::PERSON_USER;
        $lineItem->source_type     = Thread::SOURCE_TYPE_API;
        $lineItem->customer_id     = $conversation->customer_id;
        $lineItem->save();
    }
}

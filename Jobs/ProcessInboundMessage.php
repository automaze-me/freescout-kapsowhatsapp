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

                $conversation = \Eventy::filter(
                    $isNew ? 'conversation.created_by_customer' : 'conversation.customer_replied',
                    $conversation, $thread, $customer
                );

                $conversation->last_reply_at   = now();
                $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
                $conversation->setPreview($body['html']);
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
     * Fires the core Laravel events and Eventy hooks exactly once per
     * message, even across retries. `events_dispatched_at` is the source of
     * truth: a row without it means the conversation/thread/dedupe write
     * committed but the events were never confirmed dispatched (a listener
     * threw, or the worker died between commit and dispatch) — the
     * early-return-on-seen path above must not silently swallow that. A row
     * with it already set is a genuine duplicate delivery and this is a
     * no-op.
     */
    protected function dispatchPendingEvents(KapsoMessage $kapsoMessage)
    {
        if ($kapsoMessage->eventsDispatched()) {
            return;
        }

        $thread       = $kapsoMessage->thread_id ? Thread::find($kapsoMessage->thread_id) : null;
        $conversation = $thread ? $thread->conversation : null;

        if (!$thread || !$conversation) {
            return;
        }

        $customer = $conversation->customer;

        // Inbound over a webhook bypasses the mail-fetch pipeline, so
        // nothing else raises these. Without them, notifications, workflows
        // and auto-replies silently never run.
        if ($thread->first) {
            event(new CustomerCreatedConversation($conversation, $thread));
            \Eventy::action('conversation.created_by_customer', $conversation, $thread, $customer);
        } else {
            event(new CustomerReplied($conversation, $thread));
            \Eventy::action('conversation.customer_replied', $conversation, $thread, $customer);
        }

        $kapsoMessage->markEventsDispatched();
    }

    /**
     * Prefer the typed text; fall back to Kapso's rendered representation so
     * unsupported types (location, contacts, interactive) still carry
     * content. Returns both the raw text and the HTML-escaped body:
     * `Conversation::subjectFromText()` only strips tags — it never decodes
     * HTML entities — so building the subject from an already-escaped body
     * would leave literal "&amp;"/"&#039;" in conversation subjects and
     * email notification headers. The thread body and preview, on the other
     * hand, must stay escaped: the text is attacker-controlled.
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

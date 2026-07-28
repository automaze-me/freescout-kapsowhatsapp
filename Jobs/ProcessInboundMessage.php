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

        if (!$wamid || KapsoMessage::seen($wamid)) {
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

        $resolved     = (new ConversationResolver())->resolve($customer, $mailbox, Conversation::subjectFromText($body));
        $conversation = $resolved['conversation'];

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id         = null;
        $thread->type            = Thread::TYPE_CUSTOMER;
        $thread->status          = Thread::STATUS_ACTIVE;
        $thread->state           = Thread::STATE_PUBLISHED;
        $thread->body            = $body;
        $thread->source_via      = Thread::PERSON_CUSTOMER;
        $thread->source_type     = Thread::SOURCE_TYPE_API;
        $thread->customer_id     = $customer->id;
        $thread->created_by_customer_id = $customer->id;
        $thread->save();

        KapsoMessage::create([
            'account_id'            => $account->id,
            'conversation_id'       => $conversation->id,
            'thread_id'             => $thread->id,
            'wamid'                 => $wamid,
            'kapso_conversation_id' => $this->payload['conversation']['id'] ?? null,
            'direction'             => KapsoMessage::DIRECTION_INBOUND,
            'status'                => $message['kapso']['status'] ?? 'received',
            'contact_phone'         => $e164,
        ]);

        $conversation->last_reply_at   = now();
        $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
        $conversation->setPreview($body);
        $conversation->save();

        $mailbox->updateFoldersCounters();

        // Inbound over a webhook bypasses the mail-fetch pipeline, so nothing
        // else raises these. Without them, notifications, workflows and
        // auto-replies silently never run.
        if ($resolved['is_new']) {
            event(new CustomerCreatedConversation($conversation, $thread));
        } else {
            event(new CustomerReplied($conversation, $thread));
        }
    }

    /**
     * Prefer the typed text; fall back to Kapso's rendered representation so
     * unsupported types (location, contacts, interactive) still carry content.
     */
    protected function body(array $message)
    {
        $text = $message['text']['body'] ?? null;

        if (is_string($text) && trim($text) !== '') {
            return nl2br(e($text));
        }

        $content = $message['kapso']['content'] ?? '';

        if (trim((string) $content) !== '') {
            return nl2br(e($content));
        }

        return '['.__('WhatsApp message').': '.e($message['type'] ?? 'unknown').']';
    }
}

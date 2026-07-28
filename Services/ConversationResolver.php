<?php

namespace Modules\KapsoWhatsApp\Services;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;

class ConversationResolver
{
    /**
     * Append to the customer's open conversation in this mailbox regardless of
     * its channel — that is what lets a WhatsApp message land on an email
     * conversation. Closed conversations are never reopened implicitly.
     */
    public function resolve(Customer $customer, Mailbox $mailbox, $subject)
    {
        $open = Conversation::where('customer_id', $customer->id)
            ->where('mailbox_id', $mailbox->id)
            ->where('state', Conversation::STATE_PUBLISHED)
            ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
            ->orderBy('last_reply_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($open) {
            return ['conversation' => $open, 'is_new' => false];
        }

        $conversation = new Conversation();
        $conversation->type          = Conversation::TYPE_CHAT;
        $conversation->subject       = $subject;
        $conversation->mailbox_id    = $mailbox->id;
        $conversation->customer_id   = $customer->id;
        $conversation->customer_email = $customer->getMainEmail() ?: '';
        $conversation->channel       = KapsoAccount::CHANNEL;
        $conversation->source_via    = Conversation::PERSON_CUSTOMER;
        $conversation->source_type   = Conversation::SOURCE_TYPE_API;
        $conversation->state         = Conversation::STATE_PUBLISHED;
        $conversation->status        = Conversation::STATUS_ACTIVE;
        $conversation->preview       = '';
        $conversation->updateFolder();
        $conversation->save();

        return ['conversation' => $conversation, 'is_new' => true];
    }
}

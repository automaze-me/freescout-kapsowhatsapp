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
     *
     * Spam is deliberately included here (unlike Closed): mirrors
     * FetchEmails.php/Thread.php, which append a customer's further message
     * to an existing Spam conversation instead of opening a fresh one, so
     * marking a number as Spam keeps giving ongoing protection. reactivate()
     * below is what stops that append from pulling the conversation back
     * into an active folder.
     */
    public function resolve(Customer $customer, Mailbox $mailbox, $subject)
    {
        $open = Conversation::where('customer_id', $customer->id)
            ->where('mailbox_id', $mailbox->id)
            ->where('state', Conversation::STATE_PUBLISHED)
            ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING, Conversation::STATUS_SPAM])
            ->orderBy('last_reply_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($open) {
            $this->reactivate($open);

            return ['conversation' => $open, 'is_new' => false];
        }

        $conversation = new Conversation();
        $conversation->type                   = Conversation::TYPE_CHAT;
        $conversation->subject                = $subject;
        $conversation->mailbox_id             = $mailbox->id;
        $conversation->customer_id            = $customer->id;
        $conversation->customer_email         = $customer->getMainEmail() ?: '';
        $conversation->created_by_customer_id = $customer->id;
        $conversation->channel                = KapsoAccount::CHANNEL;
        $conversation->source_via             = Conversation::PERSON_CUSTOMER;
        $conversation->source_type            = Conversation::SOURCE_TYPE_API;
        $conversation->state                  = Conversation::STATE_PUBLISHED;
        $conversation->status                 = Conversation::STATUS_ACTIVE;
        $conversation->preview                = '';
        $conversation->updateFolder();
        $conversation->save();

        return ['conversation' => $conversation, 'is_new' => true];
    }

    /**
     * A customer reply must reactivate a PENDING conversation — otherwise
     * Folder::updateCountersNow() (which only counts STATUS_ACTIVE toward
     * active_count) never sees it and the sidebar badge doesn't move. Mirrors
     * FetchEmails.php: STATUS_SPAM is left untouched, the status change is
     * routed through the `conversation.status_changing` filter, and the
     * folder is only recomputed when the status actually changed. Not saved
     * here — the caller persists everything once, atomically, alongside the
     * new thread.
     */
    protected function reactivate(Conversation $conversation)
    {
        if ($conversation->status == Conversation::STATUS_ACTIVE || $conversation->status == Conversation::STATUS_SPAM) {
            return;
        }

        $oldStatus = $conversation->status;
        $conversation->status = \Eventy::filter('conversation.status_changing', Conversation::STATUS_ACTIVE, $conversation);

        if ($conversation->status != $oldStatus) {
            $conversation->updateFolder();
        }
    }
}

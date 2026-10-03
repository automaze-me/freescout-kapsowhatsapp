<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Services\DeliveryFailureLineItem;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * A message that never reached the customer must not sit unnoticed in a
 * Pending or Closed conversation. Seen in production: an agent reply sent
 * as Pending failed, and nobody saw its failure line item until the
 * customer complained two days later.
 */
class DeliveryFailureLineItemTest extends TestCase
{
    protected function makeConversation(int $status): Conversation
    {
        $mailbox  = $this->testMailbox();
        $customer = Customer::createWithoutEmail(['first_name' => 'Wanda', 'last_name' => 'WhatsApp']);

        $folder = Folder::where('mailbox_id', $mailbox->id)
            ->where('type', Folder::TYPE_UNASSIGNED)
            ->first();

        $conversation = new Conversation();
        $conversation->type        = Conversation::TYPE_CHAT;
        $conversation->channel     = KapsoAccount::CHANNEL;
        $conversation->mailbox_id  = $mailbox->id;
        $conversation->folder_id   = $folder->id;
        $conversation->customer_id = $customer->id;
        $conversation->status      = $status;
        $conversation->state       = Conversation::STATE_PUBLISHED;
        $conversation->source_via  = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_API;
        $conversation->subject     = 'WhatsApp chat';
        $conversation->preview     = '';
        $conversation->save();

        return $conversation;
    }

    public function test_a_delivery_failure_puts_a_pending_conversation_back_to_active()
    {
        $conversation = $this->makeConversation(Conversation::STATUS_PENDING);

        DeliveryFailureLineItem::create($conversation, 'Could not reach Kapso');

        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
    }

    public function test_a_delivery_failure_reopens_a_closed_conversation()
    {
        $conversation = $this->makeConversation(Conversation::STATUS_CLOSED);

        DeliveryFailureLineItem::create($conversation, 'Could not reach Kapso');

        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
    }

    /**
     * Same Spam guard as an inbound message (ConversationResolver): a number
     * marked as Spam stays out of the queue.
     */
    public function test_a_spam_conversation_stays_spam()
    {
        $conversation = $this->makeConversation(Conversation::STATUS_SPAM);

        DeliveryFailureLineItem::create($conversation, 'Could not reach Kapso');

        $this->assertSame(Conversation::STATUS_SPAM, (int) $conversation->fresh()->status);
        $this->assertSame(1, Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->count(),
            'the failure is still recorded');
    }

    /**
     * The sidebar badge is a stored counter, not a live query: without a
     * recount the reopened conversation would not show up in it.
     */
    public function test_the_reopened_conversation_counts_toward_the_active_badge()
    {
        $conversation = $this->makeConversation(Conversation::STATUS_PENDING);

        DeliveryFailureLineItem::create($conversation, 'Could not reach Kapso');

        $this->assertSame(1, (int) Folder::find($conversation->fresh()->folder_id)->active_count);
    }
}

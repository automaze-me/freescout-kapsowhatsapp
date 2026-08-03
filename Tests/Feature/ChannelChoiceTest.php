<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Thread;
use Carbon\Carbon;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Services\ChannelChoice;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * Task 1 of Stage 4: ChannelChoice is the single authority on whether a
 * conversation can be answered on WhatsApp, on email, and which one the
 * picker (Task 3) should default to. Fixture idiom (account/conversation/
 * seeded inbound row) copied from SendTemplateTest.php per this module's
 * convention of each test file owning its own fixtures rather than sharing
 * a trait.
 */
class ChannelChoiceTest extends TestCase
{
    protected function makeAccount(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill([
            'name'            => 'Support',
            'phone_number_id' => '123456789012345',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ]);
        $account->webhook_secret = 'secret';
        $account->save();

        return $account;
    }

    protected function makeConversation(KapsoAccount $account, int $channel, Customer $customer): Conversation
    {
        $folder = Folder::where('mailbox_id', $account->mailbox_id)
            ->where('type', Folder::TYPE_UNASSIGNED)
            ->first();

        $conversation = new Conversation();
        $conversation->type        = Conversation::TYPE_CHAT;
        $conversation->channel     = $channel;
        $conversation->mailbox_id  = $account->mailbox_id;
        $conversation->folder_id   = $folder->id;
        $conversation->customer_id = $customer->id;
        $conversation->status      = Conversation::STATUS_ACTIVE;
        $conversation->state       = Conversation::STATE_PUBLISHED;
        $conversation->source_via  = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_API;
        $conversation->subject     = 'Mixed conversation';
        $conversation->preview     = '';
        $conversation->save();

        return $conversation;
    }

    /**
     * $threadId is nullable and, unlike SendReplyTest/SendTemplateTest's own
     * seedInbound(), explicit here: defaultChannel()'s "newest customer
     * thread has an inbound row, matched on thread_id" rule is the whole
     * point of this file, so several tests need to pin a row to a specific
     * thread rather than leaving it null.
     */
    protected function seedInbound(KapsoAccount $account, Conversation $conversation, ?int $threadId = null, ?Carbon $createdAt = null, string $wamid = 'wamid.IN1'): KapsoMessage
    {
        $row = new KapsoMessage();
        $row->account_id      = $account->id;
        $row->conversation_id = $conversation->id;
        $row->thread_id       = $threadId;
        $row->direction       = KapsoMessage::DIRECTION_INBOUND;
        $row->wamid           = $wamid;
        $row->contact_phone   = '+491771234567';
        $row->status          = 'received';
        $row->save();

        if ($createdAt) {
            // Two saves, same idiom as WindowStateTest::seedMessage(): the
            // first insert lets Eloquent's own timestamp behaviour populate
            // created_at as usual, the second overwrites it with the
            // caller's value so it survives.
            $row->created_at = $createdAt;
            $row->save();
        }

        return $row;
    }

    protected function makeCustomerThread(Conversation $conversation): Thread
    {
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = Thread::TYPE_CUSTOMER;
        $thread->status          = Thread::STATUS_ACTIVE;
        $thread->state           = Thread::STATE_PUBLISHED;
        $thread->body            = '<p>Hi</p>';
        $thread->source_via      = Thread::PERSON_CUSTOMER;
        $thread->source_type     = Thread::SOURCE_TYPE_API;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        return $thread;
    }

    public function test_whatsapp_is_available_only_with_inbound_history()
    {
        $account      = $this->makeAccount();
        $customer     = Customer::createWithoutEmail(['first_name' => 'Wanda', 'last_name' => 'Whats']);
        $conversation = $this->makeConversation($account, 1, $customer);

        $this->assertFalse(ChannelChoice::whatsappAvailable($conversation));

        $this->seedInbound($account, $conversation);

        $this->assertTrue(ChannelChoice::whatsappAvailable($conversation));
    }

    public function test_email_is_available_only_with_a_customer_email()
    {
        $account = $this->makeAccount();

        $customerNoEmail      = Customer::createWithoutEmail(['first_name' => 'No', 'last_name' => 'Email']);
        $conversationNoEmail  = $this->makeConversation($account, 1, $customerNoEmail);
        $this->assertFalse(ChannelChoice::emailAvailable($conversationNoEmail));

        $customerWithEmail     = Customer::create('mo@example.com', ['first_name' => 'Mo']);
        $conversationWithEmail = $this->makeConversation($account, 1, $customerWithEmail);
        $this->assertTrue(ChannelChoice::emailAvailable($conversationWithEmail));
    }

    public function test_the_default_follows_the_customers_latest_message()
    {
        $account      = $this->makeAccount();
        $customer     = Customer::create('mixed@example.com', ['first_name' => 'Mo']);
        $conversation = $this->makeConversation($account, 1, $customer);

        // Older email TYPE_CUSTOMER thread (lower id) -- no kapso row.
        $this->makeCustomerThread($conversation);

        // Newer WhatsApp TYPE_CUSTOMER thread (higher id), with an inbound
        // row keyed to it, one hour old -> window open.
        $waThread = $this->makeCustomerThread($conversation);
        $this->seedInbound($account, $conversation, $waThread->id, now()->subHour());

        $this->assertSame(ChannelChoice::CHANNEL_WHATSAPP, ChannelChoice::defaultChannel($conversation));

        // An even newer email TYPE_CUSTOMER thread, no kapso row at all ->
        // the newest customer thread no longer has WhatsApp history, so the
        // default falls back to email (the customer has one).
        $this->makeCustomerThread($conversation);

        $this->assertSame(ChannelChoice::CHANNEL_EMAIL, ChannelChoice::defaultChannel($conversation));
    }

    public function test_a_closed_window_defaults_to_email()
    {
        $account      = $this->makeAccount();
        $customer     = Customer::create('closed@example.com', ['first_name' => 'Cee']);
        $conversation = $this->makeConversation($account, 1, $customer);

        $waThread = $this->makeCustomerThread($conversation);
        $this->seedInbound($account, $conversation, $waThread->id, now()->subHours(30));

        $this->assertSame(ChannelChoice::CHANNEL_EMAIL, ChannelChoice::defaultChannel($conversation));
    }

    public function test_the_native_channel_is_the_last_resort()
    {
        $account         = $this->makeAccount();
        $customerNoEmail = Customer::createWithoutEmail(['first_name' => 'No', 'last_name' => 'Email']);

        // Channel-105 conversation, closed window, customer without an
        // email -> falls all the way back to the native channel, WhatsApp.
        $waConversation = $this->makeConversation($account, KapsoAccount::CHANNEL, $customerNoEmail);
        $waThread        = $this->makeCustomerThread($waConversation);
        $this->seedInbound($account, $waConversation, $waThread->id, now()->subHours(30));

        $this->assertSame(ChannelChoice::CHANNEL_WHATSAPP, ChannelChoice::defaultChannel($waConversation));

        // Channel-1 conversation, no WhatsApp rows at all, no email ->
        // falls back to the native channel, email.
        $emailConversation = $this->makeConversation($account, 1, $customerNoEmail);

        $this->assertSame(ChannelChoice::CHANNEL_EMAIL, ChannelChoice::defaultChannel($emailConversation));
    }
}

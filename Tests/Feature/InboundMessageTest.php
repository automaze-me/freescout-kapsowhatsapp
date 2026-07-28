<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Events\CustomerCreatedConversation;
use App\Events\CustomerReplied;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

class InboundMessageTest extends TestCase
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
        $account->api_key        = 'key';
        $account->webhook_secret = 'secret';
        $account->save();

        return $account;
    }

    protected function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'message' => [
                'id'        => 'wamid.in.1',
                'timestamp' => '1730092800',
                'type'      => 'text',
                'from'      => '4915199999999',
                'text'      => ['body' => 'Hello there'],
                'kapso'     => ['direction' => 'inbound', 'has_media' => false, 'content' => 'Hello there'],
            ],
            'conversation' => [
                'id'              => 'conv_abc',
                'phone_number_id' => '123456789012345',
                'kapso'           => ['contact_name' => 'Frida Neu'],
            ],
            'is_new_conversation' => true,
            'phone_number_id'     => '123456789012345',
        ], $overrides);
    }

    public function test_new_number_creates_a_chat_conversation_with_a_customer_thread()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $customer = Customer::getCustomerByChannel(KapsoAccount::CHANNEL, '+4915199999999');
        $this->assertNotNull($customer);

        $conversation = Conversation::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(Conversation::TYPE_CHAT, (int) $conversation->type);
        $this->assertSame(KapsoAccount::CHANNEL, (int) $conversation->channel);
        $this->assertSame((int) $account->mailbox_id, (int) $conversation->mailbox_id);

        $thread = $conversation->threads()->first();
        $this->assertSame(Thread::TYPE_CUSTOMER, (int) $thread->type);
        $this->assertStringContainsString('Hello there', $thread->body);

        $this->assertTrue(KapsoMessage::seen('wamid.in.1'));
    }

    public function test_second_message_appends_to_the_open_conversation()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();
        (new ProcessInboundMessage($account->id, $this->payload([
            'message' => ['id' => 'wamid.in.2', 'text' => ['body' => 'Second']],
            'is_new_conversation' => false,
        ])))->handle();

        $customer = Customer::getCustomerByChannel(KapsoAccount::CHANNEL, '+4915199999999');

        $this->assertSame(1, Conversation::where('customer_id', $customer->id)->count());
        $this->assertSame(2, Conversation::where('customer_id', $customer->id)->firstOrFail()->threads()->count());
    }

    public function test_it_appends_to_an_open_email_conversation_of_the_same_customer()
    {
        $account = $this->makeAccount();

        $customer = Customer::createWithoutEmail(['first_name' => 'Gustav', 'last_name' => 'Mail']);
        $customer->addChannel(KapsoAccount::CHANNEL, '+4915199999999');

        $existing = new Conversation();
        $existing->type        = Conversation::TYPE_EMAIL;
        $existing->subject     = 'Existing email thread';
        $existing->mailbox_id  = $account->mailbox_id;
        $existing->customer_id = $customer->id;
        $existing->status      = Conversation::STATUS_ACTIVE;
        $existing->state       = Conversation::STATE_PUBLISHED;
        $existing->source_via  = Conversation::PERSON_CUSTOMER;
        $existing->source_type = Conversation::SOURCE_TYPE_EMAIL;
        $existing->preview     = '';
        $existing->save();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $this->assertSame(1, Conversation::where('customer_id', $customer->id)->count());
        $this->assertSame($existing->id,
            (int) KapsoMessage::where('wamid', 'wamid.in.1')->value('conversation_id'));
    }

    public function test_a_closed_conversation_does_not_absorb_new_messages()
    {
        $account = $this->makeAccount();

        $customer = Customer::createWithoutEmail(['first_name' => 'Hilde', 'last_name' => 'Closed']);
        $customer->addChannel(KapsoAccount::CHANNEL, '+4915199999999');

        $closed = new Conversation();
        $closed->type        = Conversation::TYPE_CHAT;
        $closed->subject     = 'Old';
        $closed->mailbox_id  = $account->mailbox_id;
        $closed->customer_id = $customer->id;
        $closed->status      = Conversation::STATUS_CLOSED;
        $closed->state       = Conversation::STATE_PUBLISHED;
        $closed->source_via  = Conversation::PERSON_CUSTOMER;
        $closed->source_type = Conversation::SOURCE_TYPE_API;
        $closed->preview     = '';
        $closed->save();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $this->assertSame(2, Conversation::where('customer_id', $customer->id)->count());
    }

    public function test_core_events_are_fired()
    {
        $account = $this->makeAccount();

        \Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        \Event::assertDispatched(CustomerCreatedConversation::class);
        \Event::assertNotDispatched(CustomerReplied::class);
    }

    public function test_reply_to_an_existing_conversation_fires_customer_replied()
    {
        $account = $this->makeAccount();
        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        \Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        (new ProcessInboundMessage($account->id, $this->payload([
            'message' => ['id' => 'wamid.in.3', 'text' => ['body' => 'Again']],
        ])))->handle();

        \Event::assertDispatched(CustomerReplied::class);
        \Event::assertNotDispatched(CustomerCreatedConversation::class);
    }

    public function test_duplicate_wamid_is_ignored_inside_the_job()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();
        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $this->assertSame(1, KapsoMessage::where('wamid', 'wamid.in.1')->count());
    }

    public function test_unsupported_type_falls_back_to_kapso_content()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload([
            'message' => [
                'id'    => 'wamid.in.4',
                'type'  => 'location',
                'text'  => null,
                'kapso' => ['content' => 'Location: 52.52, 13.40'],
            ],
        ])))->handle();

        $thread = Thread::whereIn('id', KapsoMessage::where('wamid', 'wamid.in.4')->pluck('thread_id'))->firstOrFail();
        $this->assertStringContainsString('52.52', $thread->body);
    }
}

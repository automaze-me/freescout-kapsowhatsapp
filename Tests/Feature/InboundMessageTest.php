<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Events\CustomerCreatedConversation;
use App\Events\CustomerReplied;
use App\Folder;
use App\Subscription;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

class InboundMessageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The API key is a module-wide setting, not a per-account attribute.
        Settings::setApiKey('key');
    }

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

        \Event::assertDispatchedTimes(CustomerCreatedConversation::class, 1);
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

    public function test_a_subscribed_user_gets_a_notification_job_for_an_inbound_message()
    {
        $account = $this->makeAccount();

        $user = $this->adminUser();
        Subscription::create([
            'user_id' => $user->id,
            'medium'  => Subscription::MEDIUM_EMAIL,
            'event'   => Subscription::EVENT_NEW_CONVERSATION,
        ]);

        // Firing the events only *registers* them in Subscription's static
        // array; core drains that array at HTTP request terminate or in
        // FetchEmails — never inside a queue worker, which is where this job
        // runs in production. The job must drain it itself, observable as
        // core's notification job being queued. Queue::fake() (not
        // Bus::fake(), see TestCase notes) because the sync driver would
        // otherwise run the notification job inline and try to send mail.
        \Queue::fake();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        \Queue::assertPushed(\App\Jobs\SendNotificationToUsers::class);
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

    public function test_html_in_the_body_is_escaped_in_the_thread()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload([
            'message' => [
                'id'   => 'wamid.in.5',
                'text' => ['body' => '<script>alert(1)</script><b>bold</b>'],
            ],
        ])))->handle();

        $thread = Thread::whereIn('id', KapsoMessage::where('wamid', 'wamid.in.5')->pluck('thread_id'))->firstOrFail();

        $this->assertStringNotContainsString('<script>', $thread->body);
        $this->assertStringNotContainsString('<b>', $thread->body);
        $this->assertStringContainsString('&lt;script&gt;', $thread->body);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $thread->body);
    }

    public function test_subject_and_preview_are_built_from_raw_text_not_html_escaped_text()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload([
            'message' => [
                'id'   => 'wamid.in.6',
                'text' => ['body' => "Hi, I can't log in & reset"],
            ],
        ])))->handle();

        $customer     = Customer::getCustomerByChannel(KapsoAccount::CHANNEL, '+4915199999999');
        $conversation = Conversation::where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame("Hi, I can't log in & reset", $conversation->subject);
        // Regression guard for the same bug relocated: Helper::textPreview()
        // strips tags but never decodes HTML entities, so a preview built
        // from the escaped body would show literal "&amp;"/"&#039;".
        $this->assertSame("Hi, I can't log in & reset", $conversation->preview);
    }

    public function test_append_reactivates_a_pending_conversation_and_updates_its_state()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $customer     = Customer::getCustomerByChannel(KapsoAccount::CHANNEL, '+4915199999999');
        $conversation = Conversation::where('customer_id', $customer->id)->firstOrFail();

        // Simulate an agent reply: FreeScout sets the conversation to PENDING.
        $conversation->status = Conversation::STATUS_PENDING;
        $conversation->save();

        (new ProcessInboundMessage($account->id, $this->payload([
            'message'             => ['id' => 'wamid.in.2', 'text' => ['body' => 'Second']],
            'is_new_conversation' => false,
        ])))->handle();

        $conversation->refresh();

        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->status);
        $this->assertSame(2, (int) $conversation->threads_count);
        $this->assertSame(Conversation::PERSON_CUSTOMER, (int) $conversation->last_reply_from);
        $this->assertNotNull($conversation->last_reply_at);
        $this->assertTrue($conversation->last_reply_at->greaterThan(now()->subMinute()));

        $unassignedFolder = Folder::where('mailbox_id', $account->mailbox_id)
            ->where('type', Folder::TYPE_UNASSIGNED)
            ->firstOrFail();

        $this->assertSame($unassignedFolder->id, $conversation->folder_id);
    }

    /**
     * Mirrors FetchEmails.php/Thread.php: a further message from a customer
     * whose conversation is marked Spam must append there rather than
     * spawning a fresh conversation -- otherwise marking a number as Spam
     * gives no ongoing protection, since every later message would open a
     * brand new ACTIVE conversation.
     */
    public function test_append_to_a_spam_conversation_lands_on_it_instead_of_creating_a_new_one()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $customer     = Customer::getCustomerByChannel(KapsoAccount::CHANNEL, '+4915199999999');
        $conversation = Conversation::where('customer_id', $customer->id)->firstOrFail();

        $conversation->status = Conversation::STATUS_SPAM;
        $conversation->save();

        (new ProcessInboundMessage($account->id, $this->payload([
            'message'             => ['id' => 'wamid.in.2', 'text' => ['body' => 'Second']],
            'is_new_conversation' => false,
        ])))->handle();

        $this->assertSame(1, Conversation::where('customer_id', $customer->id)->count());
        $this->assertSame($conversation->id,
            (int) KapsoMessage::where('wamid', 'wamid.in.2')->value('conversation_id'));
    }

    /**
     * The Spam guard in ConversationResolver::reactivate() only protects
     * anything if the open-conversation query actually surfaces Spam rows in
     * the first place -- this is the regression guard for that: appending
     * must never pull a Spam conversation back into an active folder or
     * bump its status.
     */
    public function test_append_to_a_spam_conversation_does_not_reactivate_it()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $customer     = Customer::getCustomerByChannel(KapsoAccount::CHANNEL, '+4915199999999');
        $conversation = Conversation::where('customer_id', $customer->id)->firstOrFail();

        $conversation->status = Conversation::STATUS_SPAM;
        $conversation->save();
        $spamFolderId = $conversation->folder_id;

        (new ProcessInboundMessage($account->id, $this->payload([
            'message'             => ['id' => 'wamid.in.2', 'text' => ['body' => 'Second']],
            'is_new_conversation' => false,
        ])))->handle();

        $conversation->refresh();

        $this->assertSame(Conversation::STATUS_SPAM, (int) $conversation->status);
        $this->assertSame($spamFolderId, $conversation->folder_id);
    }

    /**
     * Core does not suppress CustomerReplied (or the matching Eventy hooks)
     * for a Spam conversation -- see app/Listeners/SendNotificationToUsers.php,
     * which still receives the event and only skips *sending the
     * notification* once inside, via $event->conversation->isSpam(). This
     * module must match that: fire the event normally and let core's own
     * listener do the suppression, rather than swallowing it here.
     */
    public function test_append_to_a_spam_conversation_still_fires_customer_replied()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $customer     = Customer::getCustomerByChannel(KapsoAccount::CHANNEL, '+4915199999999');
        $conversation = Conversation::where('customer_id', $customer->id)->firstOrFail();

        $conversation->status = Conversation::STATUS_SPAM;
        $conversation->save();

        \Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        (new ProcessInboundMessage($account->id, $this->payload([
            'message'             => ['id' => 'wamid.in.2', 'text' => ['body' => 'Second']],
            'is_new_conversation' => false,
        ])))->handle();

        \Event::assertDispatched(CustomerReplied::class);
        \Event::assertNotDispatched(CustomerCreatedConversation::class);
    }

    /**
     * The test suite uses DatabaseTransactions, so a real post-commit retry
     * can't be observed here — instead this directly recreates the state a
     * crashed/threw-before-dispatch retry would find: a KapsoMessage row
     * that exists (so the early-return-on-seen path is taken) but whose
     * events_dispatched_at marker is NULL (so the events were never
     * confirmed fired).
     */
    public function test_events_fire_exactly_once_when_the_marker_is_nulled_and_the_job_reruns()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $kapsoMessage = KapsoMessage::where('wamid', 'wamid.in.1')->firstOrFail();
        $this->assertNotNull($kapsoMessage->events_dispatched_at);

        // events_dispatched_at is deliberately excluded from $fillable, so a
        // direct DB write is the only way to null it back out.
        \DB::table('kapso_whatsapp_messages')->where('id', $kapsoMessage->id)
            ->update(['events_dispatched_at' => null]);

        \Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        \Event::assertDispatchedTimes(CustomerCreatedConversation::class, 1);
        \Event::assertNotDispatched(CustomerReplied::class);

        $this->assertNotNull(KapsoMessage::find($kapsoMessage->id)->events_dispatched_at);
    }

    public function test_a_fully_processed_message_dispatches_nothing_on_rerun()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        \Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        // Same wamid: hits the early-return-on-seen path, whose
        // events_dispatched_at is already set from the run above.
        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        \Event::assertNotDispatched(CustomerCreatedConversation::class);
        \Event::assertNotDispatched(CustomerReplied::class);
    }

    public function test_a_throwing_listener_does_not_prevent_the_marker_from_being_set()
    {
        $account = $this->makeAccount();

        \Event::listen(CustomerCreatedConversation::class, function () {
            throw new \RuntimeException('listener boom');
        });

        // Must not throw: dispatchPendingEvents() wraps the event()/Eventy
        // dispatch in try/catch so a throwing listener cannot fail this job
        // and drive a queue retry that re-enters and re-fires everything.
        (new ProcessInboundMessage($account->id, $this->payload()))->handle();

        $kapsoMessage = KapsoMessage::where('wamid', 'wamid.in.1')->firstOrFail();
        $this->assertNotNull($kapsoMessage->events_dispatched_at);

        // Simulate what a queue retry after that "failure" would do:
        // re-entering must not fire the event again now that the atomic
        // claim has already been taken.
        \Event::fake([CustomerCreatedConversation::class]);
        (new ProcessInboundMessage($account->id, $this->payload()))->handle();
        \Event::assertNotDispatched(CustomerCreatedConversation::class);
    }
}

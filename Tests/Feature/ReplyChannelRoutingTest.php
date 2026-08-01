<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Thread;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\Request;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\SendReplyMessage;
use Modules\KapsoWhatsApp\Services\ChannelChoice;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * Task 2 of Stage 4: RouteReplyChannel is both halves of the
 * defer-or-intercept architecture -- capture() writes the agent's per-reply
 * choice onto thread.meta (hooked at core's `thread.before_save_from_request`
 * action), intercept() reads it back and decides whether core's own
 * `conversation.skip_send_reply_to_customer` filter should proceed natively
 * (return false, untouched) or be short-circuited into the OTHER channel's
 * send path. See "Stage 4: per-reply channel selection" ->
 * "Architecture: defer-or-intercept" / "Capture" in
 * dev-notes/specs/2026-07-28-kapso-whatsapp-design.md.
 *
 * Fixture idiom (account/conversation/seedInbound) copied from
 * ChannelChoiceTest.php per this module's convention of each test file
 * owning its own fixtures rather than sharing a trait. Hooks are fired
 * directly via \Eventy::action()/\Eventy::filter() rather than through a
 * real HTTP request, matching WindowBannerTest's hook-level convention.
 */
class ReplyChannelRoutingTest extends TestCase
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
        $conversation->subject     = 'Routing test';
        $conversation->preview     = '';
        $conversation->save();

        return $conversation;
    }

    protected function seedInbound(KapsoAccount $account, Conversation $conversation, string $wamid = 'wamid.IN1'): KapsoMessage
    {
        $row = new KapsoMessage();
        $row->account_id      = $account->id;
        $row->conversation_id = $conversation->id;
        $row->direction       = KapsoMessage::DIRECTION_INBOUND;
        $row->wamid           = $wamid;
        $row->contact_phone   = '+491771234567';
        $row->status          = 'received';
        $row->save();

        return $row;
    }

    protected function makeMessageThread(Conversation $conversation, array $overrides = []): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status              = Thread::STATUS_ACTIVE;
        $thread->state               = Thread::STATE_PUBLISHED;
        $thread->body                = '<p>Hello</p>';
        $thread->source_via           = Thread::PERSON_USER;
        $thread->source_type          = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id          = $conversation->customer_id;

        foreach ($overrides as $key => $value) {
            $thread->{$key} = $value;
        }

        $thread->save();

        return $thread;
    }

    protected function requestWithChannel($value): Request
    {
        return Request::create('/conversations/send-reply', 'POST', ['kwa_channel' => $value]);
    }

    /**
     * Matrix per the Task 2 brief: capture() only ever writes meta for a
     * TYPE_MESSAGE thread, a value that is exactly one of the two channel
     * constants, and only when ChannelChoice says that specific channel is
     * available on the conversation. Every other combination must leave the
     * thread's meta untouched.
     */
    public function test_capture_writes_the_meta_only_for_valid_available_choices()
    {
        $account           = $this->makeAccount();
        $customerNoEmail   = Customer::createWithoutEmail(['first_name' => 'No', 'last_name' => 'Email']);
        $customerWithEmail = Customer::create('agent-choice@example.com', ['first_name' => 'Em']);

        // 'whatsapp' on a channel-1 conversation WITH an inbound row ->
        // whatsappAvailable() true -> meta written.
        $rowsConversation = $this->makeConversation($account, 1, $customerNoEmail);
        $this->seedInbound($account, $rowsConversation);
        $threadWaAvailable = $this->makeMessageThread($rowsConversation);
        \Eventy::action('thread.before_save_from_request', $threadWaAvailable, $this->requestWithChannel(ChannelChoice::CHANNEL_WHATSAPP));
        $this->assertSame(ChannelChoice::CHANNEL_WHATSAPP, $threadWaAvailable->getMeta(KapsoMessage::THREAD_META_CHANNEL));

        // 'whatsapp' on a channel-1 conversation with NO rows ->
        // whatsappAvailable() false -> no meta.
        $rowlessConversation = $this->makeConversation($account, 1, $customerNoEmail);
        $threadWaUnavailable = $this->makeMessageThread($rowlessConversation);
        \Eventy::action('thread.before_save_from_request', $threadWaUnavailable, $this->requestWithChannel(ChannelChoice::CHANNEL_WHATSAPP));
        $this->assertNull($threadWaUnavailable->getMeta(KapsoMessage::THREAD_META_CHANNEL));

        // 'email' with a customer email on file -> emailAvailable() true ->
        // meta written.
        $emailConversation = $this->makeConversation($account, 1, $customerWithEmail);
        $threadEmailAvailable = $this->makeMessageThread($emailConversation);
        \Eventy::action('thread.before_save_from_request', $threadEmailAvailable, $this->requestWithChannel(ChannelChoice::CHANNEL_EMAIL));
        $this->assertSame(ChannelChoice::CHANNEL_EMAIL, $threadEmailAvailable->getMeta(KapsoMessage::THREAD_META_CHANNEL));

        // 'email' without a customer email -> emailAvailable() false -> no meta.
        $noEmailConversation = $this->makeConversation($account, 1, $customerNoEmail);
        $threadEmailUnavailable = $this->makeMessageThread($noEmailConversation);
        \Eventy::action('thread.before_save_from_request', $threadEmailUnavailable, $this->requestWithChannel(ChannelChoice::CHANNEL_EMAIL));
        $this->assertNull($threadEmailUnavailable->getMeta(KapsoMessage::THREAD_META_CHANNEL));

        // An unrecognised value -> no meta, even though the WhatsApp channel
        // is available on this conversation.
        $threadBogusValue = $this->makeMessageThread($rowsConversation);
        \Eventy::action('thread.before_save_from_request', $threadBogusValue, $this->requestWithChannel('sms'));
        $this->assertNull($threadBogusValue->getMeta(KapsoMessage::THREAD_META_CHANNEL));

        // An empty-array value (e.g. a malformed `kwa_channel[]` submission)
        // -> no meta.
        $threadArrayValue = $this->makeMessageThread($rowsConversation);
        \Eventy::action('thread.before_save_from_request', $threadArrayValue, $this->requestWithChannel([]));
        $this->assertNull($threadArrayValue->getMeta(KapsoMessage::THREAD_META_CHANNEL));

        // The field missing from the request entirely -> no meta.
        $threadMissingField = $this->makeMessageThread($rowsConversation);
        \Eventy::action('thread.before_save_from_request', $threadMissingField, Request::create('/conversations/send-reply', 'POST', []));
        $this->assertNull($threadMissingField->getMeta(KapsoMessage::THREAD_META_CHANNEL));

        // A TYPE_NOTE thread -> no meta even with an otherwise-valid,
        // available choice: notes are never sent to the customer on any
        // channel, so a channel choice on one is meaningless.
        $noteThread = $this->makeMessageThread($rowsConversation, ['type' => Thread::TYPE_NOTE]);
        \Eventy::action('thread.before_save_from_request', $noteThread, $this->requestWithChannel(ChannelChoice::CHANNEL_WHATSAPP));
        $this->assertNull($noteThread->getMeta(KapsoMessage::THREAD_META_CHANNEL));
    }

    /**
     * effective == native (or no meta at all) must return false and
     * dispatch NOTHING -- core proceeds completely untouched, whether that
     * means its full email path or (for a channel-102 conversation) falling
     * through to the existing SendReplyToWhatsApp listener.
     */
    public function test_native_choices_and_absent_meta_leave_core_untouched()
    {
        $account  = $this->makeAccount();
        $customer = Customer::createWithoutEmail(['first_name' => 'No', 'last_name' => 'Email']);

        // No meta at all.
        $conversationNoMeta = $this->makeConversation($account, 1, $customer);
        $this->seedInbound($account, $conversationNoMeta, 'wamid.NOMETA');
        $threadNoMeta = $this->makeMessageThread($conversationNoMeta);

        // Meta 'email' on a channel-1 (native-email) conversation.
        $conversationEmailNative = $this->makeConversation($account, 1, $customer);
        $this->seedInbound($account, $conversationEmailNative, 'wamid.EMAILNATIVE');
        $threadEmailNative = $this->makeMessageThread($conversationEmailNative);
        $threadEmailNative->setMeta(KapsoMessage::THREAD_META_CHANNEL, ChannelChoice::CHANNEL_EMAIL);
        $threadEmailNative->save();

        // Meta 'whatsapp' on a channel-102 (native-WhatsApp) conversation.
        $conversationWaNative = $this->makeConversation($account, KapsoAccount::CHANNEL, $customer);
        $this->seedInbound($account, $conversationWaNative, 'wamid.WANATIVE');
        $threadWaNative = $this->makeMessageThread($conversationWaNative);
        $threadWaNative->setMeta(KapsoMessage::THREAD_META_CHANNEL, ChannelChoice::CHANNEL_WHATSAPP);
        $threadWaNative->save();

        \Bus::fake();

        $this->assertFalse(\Eventy::filter('conversation.skip_send_reply_to_customer', false, $conversationNoMeta, collect([$threadNoMeta])));
        $this->assertFalse(\Eventy::filter('conversation.skip_send_reply_to_customer', false, $conversationEmailNative, collect([$threadEmailNative])));
        $this->assertFalse(\Eventy::filter('conversation.skip_send_reply_to_customer', false, $conversationWaNative, collect([$threadWaNative])));

        \Bus::assertNotDispatched(SendReplyMessage::class);
        \Bus::assertNotDispatched(\App\Jobs\SendReplyToCustomer::class);
    }

    /**
     * The first cross-channel cell: WhatsApp chosen on a non-chat
     * (channel-1) conversation. $replies mirrors core's own newest-first,
     * whole-published-history collection (SendReplyToCustomer.php:41/:75) --
     * an older, already-delivered reply sits behind the trigger, proving the
     * 3a first()-only rule: only the trigger thread's id may ever reach
     * SendReplyMessage::dispatch().
     */
    public function test_whatsapp_choice_on_an_email_conversation_dispatches_the_send_job()
    {
        $account      = $this->makeAccount();
        $customer     = Customer::create('mixed-wa@example.com', ['first_name' => 'Mo']);
        $conversation = $this->makeConversation($account, 1, $customer);
        $this->seedInbound($account, $conversation);

        $olderReply = $this->makeMessageThread($conversation);

        $triggerReply = $this->makeMessageThread($conversation);
        $triggerReply->setMeta(KapsoMessage::THREAD_META_CHANNEL, ChannelChoice::CHANNEL_WHATSAPP);
        $triggerReply->save();

        \Bus::fake();

        $result = \Eventy::filter(
            'conversation.skip_send_reply_to_customer',
            false,
            $conversation,
            collect([$triggerReply, $olderReply])
        );

        $this->assertTrue($result);

        \Bus::assertDispatched(SendReplyMessage::class, 1);
        \Bus::assertDispatched(SendReplyMessage::class, function ($job) use ($triggerReply) {
            return $job->threadId === $triggerReply->id;
        });
        \Bus::assertNotDispatched(\App\Jobs\SendReplyToCustomer::class);
    }

    /**
     * The mirror cross-channel cell: email chosen on a channel-102
     * conversation. The dispatch must replicate core's own
     * (SendReplyToCustomer.php:110-112) verbatim: conversation, the whole
     * $replies collection, the conversation's own customer, onto the
     * 'emails' queue.
     */
    public function test_email_choice_on_a_whatsapp_conversation_dispatches_cores_email_job()
    {
        $account      = $this->makeAccount();
        $customer     = Customer::create('mixed-email@example.com', ['first_name' => 'Em']);
        $conversation = $this->makeConversation($account, KapsoAccount::CHANNEL, $customer);
        $this->seedInbound($account, $conversation);

        $triggerReply = $this->makeMessageThread($conversation);
        $triggerReply->setMeta(KapsoMessage::THREAD_META_CHANNEL, ChannelChoice::CHANNEL_EMAIL);
        $triggerReply->save();

        \Bus::fake();

        $result = \Eventy::filter(
            'conversation.skip_send_reply_to_customer',
            false,
            $conversation,
            collect([$triggerReply])
        );

        $this->assertTrue($result);

        \Bus::assertDispatched(\App\Jobs\SendReplyToCustomer::class, 1);
        \Bus::assertDispatched(\App\Jobs\SendReplyToCustomer::class, function ($job) use ($conversation, $customer, $triggerReply) {
            return $job->conversation->id === $conversation->id
                && $job->customer->id === $customer->id
                && $job->threads->first()->id === $triggerReply->id;
        });
        \Bus::assertNotDispatched(SendReplyMessage::class);
    }

    /**
     * The interceptor cannot know, at intercept() time, that the agent will
     * click undo a moment later -- it dispatches unconditionally on a
     * 'whatsapp' choice, exactly as core's own native chat path does.
     * SendReplyMessage::guards() (re-fetching the thread fresh, re-checking
     * STATE_PUBLISHED) is what actually keeps an undone reply from ever
     * reaching the network -- proven here by running the job directly
     * against a thread reverted to STATE_DRAFT with an empty HTTP queue: any
     * request attempt at all throws and fails this test.
     */
    public function test_the_undone_reply_is_safe()
    {
        $account      = $this->makeAccount();
        $customer     = Customer::create('undo@example.com', ['first_name' => 'Un']);
        $conversation = $this->makeConversation($account, 1, $customer);
        $this->seedInbound($account, $conversation);

        $triggerReply = $this->makeMessageThread($conversation);
        $triggerReply->setMeta(KapsoMessage::THREAD_META_CHANNEL, ChannelChoice::CHANNEL_WHATSAPP);
        $triggerReply->save();

        \Bus::fake();

        $result = \Eventy::filter(
            'conversation.skip_send_reply_to_customer',
            false,
            $conversation,
            collect([$triggerReply])
        );

        $this->assertTrue($result);
        \Bus::assertDispatched(SendReplyMessage::class, 1);

        // Undo lands: the real DB row reverts to STATE_DRAFT, same as core's
        // own undo flow -- the interceptor above already ran and could not
        // have known this was coming.
        Thread::where('id', $triggerReply->id)->update(['state' => Thread::STATE_DRAFT]);

        $stack = HandlerStack::create(new MockHandler([]));
        KapsoClient::fakeHttp(new Client(['handler' => $stack]));

        (new SendReplyMessage($triggerReply->id))->handle();

        $this->assertSame(0, KapsoMessage::where('thread_id', $triggerReply->id)->count());
    }

    /**
     * F2 (whole-stage review, IMPORTANT): a conversation born on WhatsApp
     * keeps `customer_email = ''` forever -- nothing backfills a chat
     * conversation's own column when an email is added to the customer
     * later. Left alone, core's mail job would dispatch to '' and Swift
     * throws an un-retried RFC address exception (SEND_ERROR). The email
     * branch of intercept() must backfill the column from the customer's
     * own address before dispatching -- mirroring core's own phone->email
     * conversion backfill elsewhere.
     */
    public function test_email_choice_backfills_a_blank_conversation_customer_email()
    {
        $account      = $this->makeAccount();
        $customer     = Customer::create('backfill@example.com', ['first_name' => 'Back']);
        $conversation = $this->makeConversation($account, KapsoAccount::CHANNEL, $customer);
        $conversation->customer_email = '';
        $conversation->save();

        $this->seedInbound($account, $conversation);

        $triggerReply = $this->makeMessageThread($conversation);
        $triggerReply->setMeta(KapsoMessage::THREAD_META_CHANNEL, ChannelChoice::CHANNEL_EMAIL);
        $triggerReply->save();

        \Bus::fake();

        $result = \Eventy::filter(
            'conversation.skip_send_reply_to_customer',
            false,
            $conversation,
            collect([$triggerReply])
        );

        $this->assertTrue($result);

        $conversation->refresh();
        $this->assertSame($customer->getMainEmail(), $conversation->customer_email);

        \Bus::assertDispatched(\App\Jobs\SendReplyToCustomer::class, 1);
        \Bus::assertNotDispatched(SendReplyMessage::class);
    }
}

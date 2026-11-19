<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Thread;
use Illuminate\Support\Facades\Queue;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\SendReplyMessage;
use Modules\KapsoWhatsApp\Services\ChannelChoice;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * FreeScout 1.8.251 (#5799) adds "Also send reply to customer by email" to
 * the reply form of chat conversations, ticked by default. Wherever the
 * Stage 4 "Send via" picker is shown, it alone decides the channel, so the
 * checkbox must not add a second delivery by email to a WhatsApp reply.
 *
 * Unlike ReplyChannelRoutingTest (hook level), these go through core's real
 * send_reply request, so core's own handling of the checkbox runs before
 * RouteReplyChannel::capture(), exactly as in production. The checkbox is
 * posted ticked, as from a page where the module JS did not hide it.
 * Fixture idiom copied from ReplyChannelRoutingTest, per this module's
 * one-file-owns-its-fixtures convention.
 */
class CoreChatEmailCheckboxTest extends TestCase
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

    protected function makeWhatsAppConversation(KapsoAccount $account, Customer $customer): Conversation
    {
        $folder = Folder::where('mailbox_id', $account->mailbox_id)
            ->where('type', Folder::TYPE_UNASSIGNED)
            ->first();

        $conversation = new Conversation();
        $conversation->type        = Conversation::TYPE_CHAT;
        $conversation->channel     = KapsoAccount::CHANNEL;
        $conversation->mailbox_id  = $account->mailbox_id;
        $conversation->folder_id   = $folder->id;
        $conversation->customer_id = $customer->id;
        $conversation->status      = Conversation::STATUS_ACTIVE;
        $conversation->state       = Conversation::STATE_PUBLISHED;
        $conversation->source_via  = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_API;
        $conversation->subject     = 'Checkbox test';
        $conversation->preview     = '';
        $conversation->save();

        return $conversation;
    }

    protected function seedInbound(KapsoAccount $account, Conversation $conversation): void
    {
        $row = new KapsoMessage();
        $row->account_id      = $account->id;
        $row->conversation_id = $conversation->id;
        $row->direction       = KapsoMessage::DIRECTION_INBOUND;
        $row->wamid           = 'wamid.CHECKBOX';
        $row->contact_phone   = '+491771234567';
        $row->status          = 'received';
        $row->save();
    }

    /**
     * A channel-105 conversation whose customer has an email and (unless
     * $withWhatsAppHistory is false) an inbound WhatsApp row: exactly the
     * conversation on which core shows its checkbox AND the module shows
     * its picker.
     */
    protected function conversation(bool $withWhatsAppHistory = true): Conversation
    {
        $account      = $this->makeAccount();
        $customer     = Customer::create('anna@example.test', ['first_name' => 'Anna']);
        $conversation = $this->makeWhatsAppConversation($account, $customer);

        if ($withWhatsAppHistory) {
            $this->seedInbound($account, $conversation);
        }

        return $conversation;
    }

    protected function sendReply(Conversation $conversation, ?string $choice): Thread
    {
        $fields = [
            'action'          => 'send_reply',
            'mailbox_id'      => $conversation->mailbox_id,
            'conversation_id' => $conversation->id,
            'body'            => '<p>Your parcel left today.</p>',
            'chat_send_email' => 1,
        ];

        if ($choice !== null) {
            $fields['kwa_channel'] = $choice;
        }

        $response = $this->actingAs($this->adminUser())
            ->post('/conversation/ajax', $fields, ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertStatus(200);
        $this->assertSame('success', $response->json()['status'] ?? null, json_encode($response->json()));

        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_MESSAGE)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    protected function requireCoreCheckbox(): void
    {
        if (!defined(Thread::class.'::META_CHAT_SEND_EMAIL')) {
            $this->markTestSkipped('This core has no "Also send reply to customer by email" checkbox (before 1.8.251).');
        }
    }

    protected function assertChatSendQueued(bool $expected): void
    {
        $isChatSend = function ($job) {
            return $job->action === 'chat_conversation.send_reply';
        };

        $expected
            ? Queue::assertPushed(\App\Jobs\TriggerAction::class, $isChatSend)
            : Queue::assertNotPushed(\App\Jobs\TriggerAction::class, $isChatSend);
    }

    public function test_a_whatsapp_reply_is_not_also_emailed_by_core()
    {
        $this->requireCoreCheckbox();
        $conversation = $this->conversation();
        $this->assertTrue(ChannelChoice::pickerAvailable($conversation));

        Queue::fake();
        $reply = $this->sendReply($conversation, ChannelChoice::CHANNEL_WHATSAPP);

        // Core's checkbox branch ran: it addressed the reply to the customer.
        $this->assertSame(['anna@example.test'], $reply->getToArray());
        // Its flag is gone, so core delivers through WhatsApp only.
        $this->assertNull($reply->getMeta(Thread::META_CHAT_SEND_EMAIL));

        $this->assertChatSendQueued(true);
        Queue::assertNotPushed(\App\Jobs\SendReplyToCustomer::class);
        Queue::assertNotPushed(SendReplyMessage::class);
    }

    public function test_an_email_choice_is_sent_once()
    {
        $this->requireCoreCheckbox();
        $conversation = $this->conversation();

        Queue::fake();
        $reply = $this->sendReply($conversation, ChannelChoice::CHANNEL_EMAIL);

        $this->assertNull($reply->getMeta(Thread::META_CHAT_SEND_EMAIL));
        $this->assertSame(ChannelChoice::CHANNEL_EMAIL, $reply->getMeta(KapsoMessage::THREAD_META_CHANNEL));

        Queue::assertPushed(\App\Jobs\SendReplyToCustomer::class, 1);
        $this->assertChatSendQueued(false);
        Queue::assertNotPushed(SendReplyMessage::class);
    }

    /**
     * No picker (no WhatsApp history on this conversation, so nothing to
     * pick between): the module JS leaves core's checkbox visible, and the
     * server leaves it working as core intends, rather than turning it into
     * a control that silently does nothing.
     */
    public function test_without_the_picker_core_checkbox_keeps_working()
    {
        $this->requireCoreCheckbox();
        $conversation = $this->conversation(false);
        $this->assertFalse(ChannelChoice::pickerAvailable($conversation));

        Queue::fake();
        $reply = $this->sendReply($conversation, null);

        $this->assertSame(1, (int) $reply->getMeta(Thread::META_CHAT_SEND_EMAIL));
        $this->assertChatSendQueued(true);
        Queue::assertPushed(\App\Jobs\SendReplyToCustomer::class, 1);
    }

    /**
     * Tripwire: capture() drops core's flag by its constant and skips that
     * on cores without it. If a newer core renamed the constant, the skip
     * would hide the double delivery again, so fail loudly instead.
     */
    public function test_cores_from_1_8_251_define_the_flag_the_module_drops()
    {
        if (version_compare(\Config::get('app.version'), '1.8.251', '<')) {
            $this->markTestSkipped('Core before 1.8.251.');
        }

        $this->assertTrue(
            defined(Thread::class.'::META_CHAT_SEND_EMAIL'),
            'Core no longer defines Thread::META_CHAT_SEND_EMAIL: check how it now flags "Also send reply to customer by email".'
        );
    }
}

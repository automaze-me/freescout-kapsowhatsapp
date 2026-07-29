<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Jobs\ReconcileOutboundMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

class ReconcileOutboundTest extends TestCase
{
    protected function makeAccount(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill([
            'name' => 'Support', 'phone_number_id' => '123456789012345',
            'mailbox_id' => $this->testMailbox()->id, 'is_active' => true,
        ]);
        $account->api_key        = 'key';
        $account->webhook_secret = 'secret';
        $account->save();

        return $account;
    }

    protected function seedInbound(KapsoAccount $account): void
    {
        (new ProcessInboundMessage($account->id, [
            'message' => [
                'id' => 'wamid.seed', 'type' => 'text', 'from' => '4915166666666',
                'text' => ['body' => 'Hi'],
                'kapso' => ['direction' => 'inbound', 'has_media' => false, 'content' => 'Hi'],
            ],
            'conversation' => [
                'id' => 'conv_echo', 'phone_number_id' => '123456789012345',
                'kapso' => ['contact_name' => 'Echo Tester'],
            ],
            'is_new_conversation' => true,
            'phone_number_id'     => '123456789012345',
        ]))->handle();
    }

    protected function sentPayload(string $wamid): array
    {
        return [
            'message' => [
                'id' => $wamid, 'type' => 'text', 'to' => '4915166666666',
                'text' => ['body' => 'Reply from elsewhere'],
                'kapso' => ['direction' => 'outbound', 'status' => 'sent', 'content' => 'Reply from elsewhere'],
            ],
            'conversation' => [
                'id' => 'conv_echo', 'phone_number_id' => '123456789012345',
                'kapso' => ['contact_name' => 'Echo Tester'],
            ],
            'phone_number_id' => '123456789012345',
        ];
    }

    public function test_our_own_send_is_ignored()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        $conversationId = KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id');

        // Simulate a send this module already recorded.
        KapsoMessage::create([
            'account_id'      => $account->id,
            'conversation_id' => $conversationId,
            'thread_id'       => null,
            'wamid'           => 'wamid.ours',
            'direction'       => KapsoMessage::DIRECTION_OUTBOUND,
            'contact_phone'   => '+4915166666666',
        ]);

        $threadsBefore = Thread::where('conversation_id', $conversationId)->count();

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $this->sentPayload('wamid.ours')))->handle();

        $this->assertSame($threadsBefore, Thread::where('conversation_id', $conversationId)->count(),
            'a send we already know about must not produce a second thread');
    }

    public function test_a_foreign_send_is_recorded_as_an_outbound_thread()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        $conversationId = KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id');
        $threadsBefore  = Thread::where('conversation_id', $conversationId)->count();

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $this->sentPayload('wamid.foreign')))->handle();

        $this->assertSame($threadsBefore + 1, Thread::where('conversation_id', $conversationId)->count());

        $thread = Thread::where('conversation_id', $conversationId)->orderBy('id', 'desc')->first();
        $this->assertSame(Thread::TYPE_MESSAGE, (int) $thread->type);
        $this->assertStringContainsString('Reply from elsewhere', $thread->body);
        $this->assertTrue(KapsoMessage::seen('wamid.foreign'));
    }

    public function test_a_failed_send_is_surfaced_on_the_conversation()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        $conversationId = KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id');

        KapsoMessage::create([
            'account_id'      => $account->id,
            'conversation_id' => $conversationId,
            'wamid'           => 'wamid.failing',
            'direction'       => KapsoMessage::DIRECTION_OUTBOUND,
            'contact_phone'   => '+4915166666666',
        ]);

        $payload = $this->sentPayload('wamid.failing');
        $payload['message']['kapso']['status']   = 'failed';
        $payload['message']['kapso']['statuses'] = [[
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message',
                          'message' => 'Message failed to send because more than 24 hours have passed']],
        ]];

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $payload))->handle();

        $record = KapsoMessage::where('wamid', 'wamid.failing')->firstOrFail();
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('131047', (string) $record->error);

        $lineItem = Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_LINEITEM)->orderBy('id', 'desc')->first();
        $this->assertNotNull($lineItem, 'a delivery failure must be visible on the conversation');
    }

    public function test_a_send_for_an_unknown_conversation_is_dropped_quietly()
    {
        $account = $this->makeAccount();

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $this->sentPayload('wamid.orphan')))->handle();

        $this->assertFalse(KapsoMessage::seen('wamid.orphan'));
    }
}

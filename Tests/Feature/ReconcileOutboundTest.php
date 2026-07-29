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

    protected function failedPayload(string $wamid): array
    {
        $payload = $this->sentPayload($wamid);

        $payload['message']['kapso']['status']   = 'failed';
        $payload['message']['kapso']['statuses'] = [[
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message',
                          'message' => 'Message failed to send because more than 24 hours have passed']],
        ]];

        return $payload;
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

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $this->failedPayload('wamid.failing')))->handle();

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

    /**
     * The critical ordering bug: Kapso does not guarantee `sent` arrives
     * before `failed`, and some error classes (e.g. 131047, used here) may
     * never produce a `sent` event at all. Before the fix, recordFailure()
     * started with `if (!$known) { return; }`, so a `failed` event with no
     * prior row vanished with no thread, no line item, and not even a log
     * line — and a later `sent` for the same wamid would then record the
     * message as an ordinary successful outbound thread.
     */
    public function test_a_failed_event_with_no_prior_row_creates_a_thread_and_a_line_item()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        $conversationId = KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id');
        $threadsBefore  = Thread::where('conversation_id', $conversationId)->count();

        $this->assertFalse(KapsoMessage::seen('wamid.failed-first'), 'precondition: no row exists yet for this wamid');

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $this->failedPayload('wamid.failed-first')))->handle();

        $record = KapsoMessage::where('wamid', 'wamid.failed-first')->firstOrFail();
        $this->assertSame(KapsoMessage::DIRECTION_OUTBOUND, $record->direction);
        $this->assertFalse((bool) $record->is_reaction);
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('131047', (string) $record->error);
        $this->assertEquals($conversationId, $record->conversation_id);
        $this->assertNotNull($record->thread_id);

        $threads = Thread::where('conversation_id', $conversationId)->orderBy('id')->get();
        $this->assertSame($threadsBefore + 2, $threads->count(),
            'a failed send with no prior row must produce both an outbound thread and a line item');

        $messageThread = Thread::find($record->thread_id);
        $this->assertSame(Thread::TYPE_MESSAGE, (int) $messageThread->type);
        $this->assertStringContainsString('Reply from elsewhere', $messageThread->body);

        $lineItem = Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_LINEITEM)->orderBy('id', 'desc')->first();
        $this->assertNotNull($lineItem, 'a delivery failure must be visible on the conversation even with no prior row');
        $this->assertStringContainsString('131047', $lineItem->body);
    }

    public function test_a_failed_event_for_an_unknown_conversation_is_dropped_quietly()
    {
        $account = $this->makeAccount();

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $this->failedPayload('wamid.failed-orphan')))->handle();

        $this->assertFalse(KapsoMessage::seen('wamid.failed-orphan'));
    }

    /**
     * A `sent` event processed after a `failed` event for the same wamid
     * (the sibling event finally arriving, or a retried job catching up)
     * must not resurrect the message as delivered.
     */
    public function test_a_sent_event_after_a_failed_event_does_not_resurrect_the_message()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        $conversationId = KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id');

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $this->failedPayload('wamid.late-sent')))->handle();

        $threadsAfterFailure = Thread::where('conversation_id', $conversationId)->count();
        $lineItemsAfterFailure = Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_LINEITEM)->count();

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $this->sentPayload('wamid.late-sent')))->handle();

        $record = KapsoMessage::where('wamid', 'wamid.late-sent')->firstOrFail();
        $this->assertSame('failed', $record->status, 'a later sent event must not clear the failed status');
        $this->assertNotNull($record->error, 'a later sent event must not wipe the recorded error');

        $this->assertSame($threadsAfterFailure, Thread::where('conversation_id', $conversationId)->count(),
            'a later sent event must not add a second thread');
        $this->assertSame($lineItemsAfterFailure, Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_LINEITEM)->count(),
            'a later sent event must not add a second line item');
    }

    public function test_a_duplicate_sent_event_produces_exactly_one_thread()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        $conversationId = KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id');
        $threadsBefore  = Thread::where('conversation_id', $conversationId)->count();

        $payload = $this->sentPayload('wamid.dup-sent');

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $payload))->handle();
        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $payload))->handle();

        $this->assertSame($threadsBefore + 1, Thread::where('conversation_id', $conversationId)->count(),
            'a duplicate delivery of the same sent event must not produce a second thread');
        $this->assertSame(1, KapsoMessage::where('wamid', 'wamid.dup-sent')->count());
    }

    public function test_a_duplicate_failed_event_produces_exactly_one_line_item()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        $conversationId = KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id');

        $payload = $this->failedPayload('wamid.dup-failed');

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $payload))->handle();

        $lineItemsAfterFirst = Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_LINEITEM)->count();
        $this->assertSame(1, $lineItemsAfterFirst);

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $payload))->handle();

        $this->assertSame($lineItemsAfterFirst, Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_LINEITEM)->count(),
            'a duplicate delivery of the same failed event must not produce a second line item');
        $this->assertSame(1, KapsoMessage::where('wamid', 'wamid.dup-failed')->count());
    }
}

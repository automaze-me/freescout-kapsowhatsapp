<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Jobs\ReconcileOutboundMessage;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

class ReconcileOutboundTest extends TestCase
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
            'name' => 'Support', 'phone_number_id' => '123456789012345',
            'mailbox_id' => $this->testMailbox()->id, 'is_active' => true,
        ]);
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

        // Core's print view calls $thread->created_by_user_cached->getFullName()
        // with no null guard for any non-customer thread -- fatal if
        // created_by_user_id is null. No real FreeScout agent authored this
        // thread, so it must be attributed to the module's synthetic user
        // rather than left null.
        $this->assertNotNull($thread->created_by_user_id,
            'a module-created thread with no created_by_user_id makes core\'s print view fatal');
        $this->assertNotNull($thread->created_by_user_cached);
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

        // The row existing is not the same as the agent seeing anything: core
        // renders line items exclusively via getActionText() (never
        // $thread->body directly -- see thread.blade.php), and
        // Thread::getActionText() has no branch for a NULL action_type, which
        // is exactly what this line item carries. Without
        // KapsoWhatsAppServiceProvider's `thread.action_text` filter, this
        // call returns '' and the conversation shows a blank grey bar with a
        // timestamp and no text -- indistinguishable from success next to a
        // normal-looking "Sent outside FreeScout" thread. This is the exact
        // call thread.blade.php makes for a TYPE_LINEITEM thread.
        $rendered = $lineItem->getActionText('', true, false, null, 'Some Agent');
        $this->assertNotSame('', trim(strip_tags($rendered)),
            'the failure line item must render visible text via getActionText(), not a blank bar');
        $this->assertStringContainsString('131047', $rendered);
        $this->assertStringContainsString(__('WhatsApp delivery failed:'), $rendered);
    }

    public function test_a_send_for_an_unknown_conversation_is_dropped_quietly()
    {
        $account = $this->makeAccount();

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $this->sentPayload('wamid.orphan')))->handle();

        $this->assertFalse(KapsoMessage::seen('wamid.orphan'));
    }

    /**
     * Kapso's own docs state that `phone_number`, `from`, `to` and `wa_id`
     * are not always present on every event. Before the fix, a missing `to`
     * made recordForeignSend() give up immediately (with no log at all) --
     * every reply sent from elsewhere for that event vanished without trace.
     * `conversation.phone_number` is a second, independent field that can be
     * present even when `to` is not.
     */
    public function test_a_missing_to_field_falls_back_to_conversation_phone_number()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        $conversationId = KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id');
        $threadsBefore  = Thread::where('conversation_id', $conversationId)->count();

        $payload = $this->sentPayload('wamid.no-to-field');
        unset($payload['message']['to']);
        $payload['conversation']['phone_number'] = '4915166666666';

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $payload))->handle();

        $this->assertSame($threadsBefore + 1, Thread::where('conversation_id', $conversationId)->count(),
            'a missing `to` must still resolve the conversation via conversation.phone_number');
        $this->assertTrue(KapsoMessage::seen('wamid.no-to-field'));

        $record = KapsoMessage::where('wamid', 'wamid.no-to-field')->firstOrFail();
        $this->assertSame('+4915166666666', $record->contact_phone);
        $this->assertEquals($conversationId, $record->conversation_id);
    }

    /**
     * `kapso_conversation_id` is written on every row this module creates and
     * otherwise never read anywhere -- it is the fallback key robust enough
     * to survive a payload with no phone number field at all (neither `to`
     * nor `conversation.phone_number`). This is the delivery-failure sibling
     * of the test above: before the fix, recordFailureForUnknownSend() at
     * least logged this case, but still dropped the failure with nowhere
     * left to attach it.
     */
    public function test_a_failed_event_with_no_phone_number_at_all_falls_back_to_kapso_conversation_id()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        $conversationId = KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id');
        $threadsBefore  = Thread::where('conversation_id', $conversationId)->count();

        $payload = $this->failedPayload('wamid.no-phone-at-all');
        unset($payload['message']['to']);
        // No conversation.phone_number either: only conversation.id (matching
        // the seeded row's kapso_conversation_id) survives in this payload.

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $payload))->handle();

        $record = KapsoMessage::where('wamid', 'wamid.no-phone-at-all')->first();
        $this->assertNotNull($record,
            'a failure with no phone number field at all must still be recorded via kapso_conversation_id');
        $this->assertEquals($conversationId, $record->conversation_id);
        $this->assertSame('failed', $record->status);
        $this->assertNull($record->contact_phone, 'no phone number was ever resolvable for this event');

        $this->assertSame($threadsBefore + 2, Thread::where('conversation_id', $conversationId)->count(),
            'must still produce both an outbound thread and a visible line item');
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
        $this->assertNotNull($messageThread->created_by_user_id,
            'a module-created thread with no created_by_user_id makes core\'s print view fatal');

        $lineItem = Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_LINEITEM)->orderBy('id', 'desc')->first();
        $this->assertNotNull($lineItem, 'a delivery failure must be visible on the conversation even with no prior row');
        $this->assertStringContainsString('131047', $lineItem->body);

        // Same rendering guarantee as the "already known row" path above,
        // exercised here via the other call site (recordFailureForUnknownSend
        // -> createFailureLineItem): the body containing the text is not
        // enough, core only ever renders getActionText().
        $rendered = $lineItem->getActionText('', true, false, null, 'Some Agent');
        $this->assertNotSame('', trim(strip_tags($rendered)),
            'the failure line item must render visible text via getActionText(), not a blank bar');
        $this->assertStringContainsString('131047', $rendered);
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

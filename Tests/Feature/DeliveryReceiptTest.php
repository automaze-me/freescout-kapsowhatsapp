<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessDeliveryReceipt;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * WhatsApp delivery receipts (user request 2026-08-02: "do both, delivered
 * and read, like real WhatsApp"): `whatsapp.message.delivered` /
 * `whatsapp.message.read` events stamp the outbound row's status and the
 * reply thread's meta, and the thread.meta remark upgrades
 * sent -> delivered -> read, rendering exactly one line (the highest
 * state) like WhatsApp's own single tick row. Receipts are best-effort by
 * nature -- a customer can disable them -- so absence never means unread,
 * and nothing here gates the send-health machinery (the sent marker's
 * delivered-and-healthy invariant is untouched; only its RENDERING is
 * superseded by a higher tick).
 *
 * Fixture idiom (makeAccount/seedInbound) copied from ReconcileOutboundTest
 * per the module's each-file-owns-its-fixtures convention.
 */
class DeliveryReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
                'id' => 'conv_receipt', 'phone_number_id' => '123456789012345',
                'kapso' => ['contact_name' => 'Receipt Tester'],
            ],
            'is_new_conversation' => true,
            'phone_number_id'     => '123456789012345',
        ]))->handle();
    }

    /**
     * "Our own send" exactly as SendReplyMessage::claimAndSend() leaves it:
     * an agent reply thread plus its claim row with thread_id, wamid and
     * send_state accepted.
     */
    protected function makeOwnSend(KapsoAccount $account, string $wamid = 'wamid.mine'): Thread
    {
        $conversation = Conversation::findOrFail(
            KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id')
        );

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = Thread::TYPE_MESSAGE;
        $thread->status          = Thread::STATUS_ACTIVE;
        $thread->state           = Thread::STATE_PUBLISHED;
        $thread->body            = 'Agent reply';
        $thread->source_via      = Thread::PERSON_USER;
        $thread->source_type     = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        KapsoMessage::create([
            'account_id'      => $account->id,
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'wamid'           => $wamid,
            'part_key'        => KapsoMessage::PART_BODY,
            'direction'       => KapsoMessage::DIRECTION_OUTBOUND,
            'send_state'      => KapsoMessage::SEND_STATE_ACCEPTED,
            'status'          => 'sent',
            'contact_phone'   => '+4915166666666',
        ]);

        return $thread;
    }

    protected function receiptPayload(string $wamid): array
    {
        return ['message' => ['id' => $wamid]];
    }

    protected function renderThreadMeta(Thread $thread): string
    {
        $conversation = Conversation::findOrFail($thread->conversation_id);

        ob_start();
        \Eventy::action('thread.meta', $thread, null, collect([$thread]), $conversation, $conversation->mailbox);

        return ob_get_clean();
    }

    public function test_a_delivered_receipt_stamps_the_row_and_the_thread()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);
        $thread = $this->makeOwnSend($account);

        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.delivered', $this->receiptPayload('wamid.mine')))->handle();

        $this->assertSame('delivered', KapsoMessage::where('wamid', 'wamid.mine')->value('status'));

        $thread = $thread->fresh();
        $this->assertNotEmpty($thread->getMeta(KapsoMessage::THREAD_META_DELIVERED_AT));
        $this->assertNull($thread->getMeta(KapsoMessage::THREAD_META_READ_AT));

        $html = $this->renderThreadMeta($thread);
        $this->assertStringContainsString('Delivered via WhatsApp', $html);
        $this->assertStringNotContainsString('Seen by customer', $html);
    }

    public function test_a_read_receipt_stamps_read_and_implies_delivered()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);
        $thread = $this->makeOwnSend($account);

        // Read arrives WITHOUT a prior delivered event -- out-of-order or
        // dropped deliveries are normal webhook life.
        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.read', $this->receiptPayload('wamid.mine')))->handle();

        $this->assertSame('read', KapsoMessage::where('wamid', 'wamid.mine')->value('status'));

        $thread = $thread->fresh();
        $this->assertNotEmpty($thread->getMeta(KapsoMessage::THREAD_META_READ_AT));
        $this->assertNotEmpty($thread->getMeta(KapsoMessage::THREAD_META_DELIVERED_AT),
            'read implies delivered');
    }

    public function test_receipts_never_downgrade_and_never_touch_a_failed_row()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);
        $thread = $this->makeOwnSend($account);

        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.read', $this->receiptPayload('wamid.mine')))->handle();
        $readAt = $thread->fresh()->getMeta(KapsoMessage::THREAD_META_READ_AT);

        // A late delivered event after read: no downgrade, timestamps stay.
        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.delivered', $this->receiptPayload('wamid.mine')))->handle();
        $this->assertSame('read', KapsoMessage::where('wamid', 'wamid.mine')->value('status'));
        $this->assertSame($readAt, $thread->fresh()->getMeta(KapsoMessage::THREAD_META_READ_AT));

        // A failed row is never resurrected by a receipt.
        $failed = $this->makeOwnSend($account, 'wamid.failed');
        KapsoMessage::where('wamid', 'wamid.failed')->update(['status' => 'failed']);

        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.delivered', $this->receiptPayload('wamid.failed')))->handle();

        $this->assertSame('failed', KapsoMessage::where('wamid', 'wamid.failed')->value('status'));
        $this->assertNull($failed->fresh()->getMeta(KapsoMessage::THREAD_META_DELIVERED_AT),
            'a failed message must not gain a delivered remark');
    }

    public function test_receipts_for_unknown_foreign_or_inbound_wamids_are_noops()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);

        // Unknown wamid: nothing to do, nothing thrown.
        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.read', $this->receiptPayload('wamid.unknown')))->handle();

        // A row without a thread (unreconciled foreign send): no-op.
        KapsoMessage::create([
            'account_id'      => $account->id,
            'conversation_id' => KapsoMessage::where('wamid', 'wamid.seed')->value('conversation_id'),
            'thread_id'       => null,
            'wamid'           => 'wamid.threadless',
            'direction'       => KapsoMessage::DIRECTION_OUTBOUND,
            'contact_phone'   => '+4915166666666',
        ]);
        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.read', $this->receiptPayload('wamid.threadless')))->handle();
        $this->assertNotSame('read', KapsoMessage::where('wamid', 'wamid.threadless')->value('status'));

        // An INBOUND wamid (the customer's own message): receipts are about
        // OUR sends; the customer thread must never gain a tick remark.
        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.read', $this->receiptPayload('wamid.seed')))->handle();

        $inboundThread = Thread::findOrFail(KapsoMessage::where('wamid', 'wamid.seed')->value('thread_id'));
        $this->assertNull($inboundThread->getMeta(KapsoMessage::THREAD_META_READ_AT));
    }

    /**
     * One remark line, highest state wins -- like WhatsApp's own single
     * tick row: read beats delivered beats the plain sent marker. The
     * SENT-marker meta itself stays untouched (its delivered-and-healthy
     * invariant is not a rendering concern).
     */
    public function test_the_remark_upgrades_sent_delivered_read_one_line_only()
    {
        $account = $this->makeAccount();
        $this->seedInbound($account);
        $thread = $this->makeOwnSend($account);

        $thread->setMeta(KapsoMessage::THREAD_META_SENT_AT, now()->toIso8601String());
        $thread->save();

        $html = $this->renderThreadMeta($thread->fresh());
        $this->assertStringContainsString('Sent via WhatsApp', $html);

        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.delivered', $this->receiptPayload('wamid.mine')))->handle();
        $html = $this->renderThreadMeta($thread->fresh());
        $this->assertStringContainsString('Delivered via WhatsApp', $html);
        $this->assertStringNotContainsString('Sent via WhatsApp', $html, 'exactly one line: the highest state');

        (new ProcessDeliveryReceipt($account->id, 'whatsapp.message.read', $this->receiptPayload('wamid.mine')))->handle();
        $html = $this->renderThreadMeta($thread->fresh());
        $this->assertStringContainsString('Seen by customer', $html);
        $this->assertStringContainsString('kwa-ticks-read', $html, 'the read state carries the blue-ticks class');
        $this->assertStringNotContainsString('Delivered via WhatsApp', $html);
        $this->assertStringNotContainsString('Sent via WhatsApp', $html);

        $this->assertNotEmpty($thread->fresh()->getMeta(KapsoMessage::THREAD_META_SENT_AT),
            'the sent marker meta itself must survive -- only its rendering is superseded');
    }
}

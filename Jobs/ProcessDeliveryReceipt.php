<?php

namespace Modules\KapsoWhatsApp\Jobs;

use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;

/**
 * Handles `whatsapp.message.delivered` / `whatsapp.message.read` webhook
 * events: stamps the outbound row's status (forward-only) and the reply
 * thread's meta so the remark under the reply upgrades sent -> delivered ->
 * read, like WhatsApp's own ticks.
 *
 * Deliberately a separate, lean job rather than more branches in
 * ReconcileOutboundMessage: receipts are advisory presence signals with
 * none of that job's claim/marker/failure obligations, and they must never
 * be able to disturb it. Everything here is idempotent and tolerant --
 * webhook events arrive out of order (a read before its delivered is
 * normal), duplicated, or not at all (a customer can disable read
 * receipts; absence never means unread).
 */
class ProcessDeliveryReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Forward-only ranking for the row's `status` column (Kapso's own
     * status vocabulary): a late `delivered` after `read` must not
     * downgrade, and `failed` (rank ceiling, set by the reconciler) is
     * never overwritten by a receipt -- a failure already surfaced as the
     * red line item, and "delivered" resurrecting it would lie.
     */
    const STATUS_RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    public $accountId;
    public $event;
    public $payload;

    public function __construct($accountId, $event, array $payload)
    {
        $this->accountId = $accountId;
        $this->event     = $event;
        $this->payload   = $payload;
    }

    public function handle()
    {
        $wamid = $this->payload['message']['id'] ?? null;

        if (!is_string($wamid) || $wamid === '') {
            return;
        }

        $kind = $this->event === 'whatsapp.message.read' ? 'read' : 'delivered';

        $row = KapsoMessage::where('wamid', $wamid)->first();

        // Only rows that are a message OF OURS with a thread to annotate:
        // unknown wamids, thread-less (unreconciled foreign) rows and
        // inbound rows (the customer's own messages -- their wamids also
        // live in this table) all no-op. Receipts are about what happened
        // to a reply an agent can see on screen; there is nothing honest to
        // stamp anywhere else.
        if (!$row || !$row->thread_id || $row->direction !== KapsoMessage::DIRECTION_OUTBOUND) {
            \Log::info('[KapsoWhatsApp] Delivery receipt with no matching outbound thread, ignored', [
                'event' => $this->event,
                'wamid' => $wamid,
            ]);

            return;
        }

        if ($row->status === 'failed') {
            \Log::info('[KapsoWhatsApp] Delivery receipt for a failed message, ignored -- failure outcome stands', [
                'event' => $this->event,
                'wamid' => $wamid,
            ]);

            return;
        }

        $currentRank = self::STATUS_RANK[$row->status] ?? 0;

        if (self::STATUS_RANK[$kind] > $currentRank) {
            $row->status = $kind;
            $row->save();
        }

        $thread = Thread::find($row->thread_id);

        if (!$thread) {
            return;
        }

        $now     = now()->toIso8601String();
        $changed = false;

        // Read implies delivered: the device that showed the message
        // necessarily received it, and a dropped/late delivered event must
        // not leave a "seen" reply with no delivered timestamp.
        if (!$thread->getMeta(KapsoMessage::THREAD_META_DELIVERED_AT)) {
            $thread->setMeta(KapsoMessage::THREAD_META_DELIVERED_AT, $now);
            $changed = true;
        }

        if ($kind === 'read' && !$thread->getMeta(KapsoMessage::THREAD_META_READ_AT)) {
            $thread->setMeta(KapsoMessage::THREAD_META_READ_AT, $now);
            $changed = true;
        }

        if ($changed) {
            $thread->save();
        }
    }
}

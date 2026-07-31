<?php

namespace Modules\KapsoWhatsApp\Services;

use App\Conversation;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;

/**
 * Is WhatsApp's 24h customer-service window open for a conversation right
 * now? Advisory only -- Meta is the actual enforcer; this class only drives
 * the UI (Stage 3b's banner + reply-editor block), never a server-side send
 * block. See "Stage 3b: 24h-window awareness" in
 * dev-notes/specs/2026-07-28-kapso-whatsapp-design.md.
 *
 * The window is per **contact**, not per FreeScout conversation: Meta's rule
 * is 24h since the customer's last inbound message to this business number,
 * full stop -- FreeScout conversation boundaries (a closed-and-reopened
 * conversation, a split thread, a second conversation for the same contact)
 * are irrelevant to Meta and must not reset or fragment the window. So
 * forConversation() first finds the conversation's own latest inbound row
 * only to learn *which* (account_id, contact_phone) pair to ask about, then
 * answers the question against every row for that pair, across all
 * conversations. This mirrors the account/contact derivation
 * SendReplyMessage::guards() already uses (Jobs/SendReplyMessage.php) --
 * same "walk from the conversation's own latest inbound row" approach,
 * reused here for the same reason: `kapso_whatsapp_messages` is the single
 * source of truth for who a conversation's WhatsApp contact actually is.
 */
class WindowState
{
    /**
     * WhatsApp's customer-service window length, per Meta's policy.
     */
    const WINDOW_HOURS = 24;

    /**
     * Returns null when the conversation is not on the WhatsApp channel, or
     * has no inbound WhatsApp message at all (nothing has ever opened a
     * window for it). Otherwise:
     *   ['open' => bool, 'last_inbound_at' => Carbon, 'closes_at' => Carbon]
     * `closes_at` is `last_inbound_at + WINDOW_HOURS`; `open` is whether
     * `closes_at` is still in the future. An inbound message landing exactly
     * WINDOW_HOURS ago reads as CLOSED: `isFuture()` on an equal-or-past
     * instant is false, and the window is defined as open only strictly
     * before the boundary, matching Meta's own "within 24 hours" wording.
     */
    public static function forConversation(Conversation $conversation): ?array
    {
        if ((int) $conversation->channel !== KapsoAccount::CHANNEL) {
            return null;
        }

        $anchor = KapsoMessage::where('conversation_id', $conversation->id)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->orderBy('id', 'desc')
            ->first();

        if (!$anchor) {
            return null;
        }

        // Re-query across every conversation for this (account, contact)
        // pair -- not just this conversation -- so a customer who also wrote
        // on a different (newer) conversation still reopens this one's
        // window. Ordered by created_at, not id: created_at is the value
        // that actually decides openness, and this module's own fixtures
        // (and any future backfill) may not insert rows in timestamp order.
        $last = KapsoMessage::where('account_id', $anchor->account_id)
            ->where('contact_phone', $anchor->contact_phone)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->orderBy('created_at', 'desc')
            ->first();

        $closesAt = $last->created_at->copy()->addHours(self::WINDOW_HOURS);

        return [
            'open'            => $closesAt->isFuture(),
            'last_inbound_at' => $last->created_at,
            'closes_at'       => $closesAt,
        ];
    }
}

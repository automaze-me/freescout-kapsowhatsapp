<?php

namespace Modules\KapsoWhatsApp\Services;

use App\Conversation;
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
     * Stage 3b fix wave: forConversation() now runs twice on the same
     * request (once for the conversation.reply_button.enabled filter, once
     * more for the after_subject/after_subject_block banner), so the second
     * call is served from here instead of repeating the two queries below.
     * Keyed by conversation id only -- every test in this module's suite
     * that seeds messages and then calls forConversation() does so for a
     * conversation created fresh via makeConversation() in that same test
     * (a new auto-increment id every time, since DatabaseTransactions rolls
     * back rows but MySQL/InnoDB never rewinds the counter), so there is no
     * scenario in the current suite where a stale cache entry could be
     * observed. No flush() method exists for the same reason: nothing needs
     * one yet. A future test that seeds a message, calls forConversation(),
     * then seeds another message for the *same* conversation and expects a
     * changed answer would need one (or a cache key that also folds in the
     * latest inbound row id) -- add it then, not speculatively now.
     *
     * Each entry stores the Carbon instances actually computed, but
     * forConversation() below always hands back ->copy() of them (never the
     * stored instances themselves): core's own date helpers
     * (\App\User::dateDiffForHumans() / ::dateFormat(), used by the window
     * banner partial) mutate the Carbon they're given via ->setTimezone() in
     * place, and without copying on every return -- not just once at
     * computation time -- a caller's mutation of one call's result would
     * corrupt what every later call for the same conversation hands back.
     *
     * @var array<int, array{open: bool, last_inbound_at: \Carbon\Carbon, closes_at: \Carbon\Carbon}|null>
     */
    private static $cache = [];

    /**
     * Returns null when the conversation has no inbound WhatsApp message at
     * all (nothing has ever opened a window for it) -- true of a
     * never-messaged conversation on ANY channel, not only channel 105:
     * Stage 4 generalises this class off the channel column entirely, since
     * a channel-1 (or other) conversation can carry WhatsApp history too
     * (Decision D6: inbound appends to the customer's open conversation
     * regardless of its channel). Otherwise:
     *   ['open' => bool, 'last_inbound_at' => Carbon, 'closes_at' => Carbon]
     * `closes_at` is `last_inbound_at + WINDOW_HOURS`; `open` is whether
     * `closes_at` is still in the future. An inbound message landing exactly
     * WINDOW_HOURS ago reads as CLOSED: `isFuture()` on an equal-or-past
     * instant is false, and the window is defined as open only strictly
     * before the boundary, matching Meta's own "within 24 hours" wording.
     */
    public static function forConversation(Conversation $conversation): ?array
    {
        if (!array_key_exists($conversation->id, self::$cache)) {
            self::$cache[$conversation->id] = self::compute($conversation);
        }

        $state = self::$cache[$conversation->id];

        if ($state === null) {
            return null;
        }

        return [
            'open'            => $state['open'],
            'last_inbound_at' => $state['last_inbound_at']->copy(),
            'closes_at'       => $state['closes_at']->copy(),
        ];
    }

    private static function compute(Conversation $conversation): ?array
    {
        $anchor = KapsoMessage::where('conversation_id', $conversation->id)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->orderBy('id', 'desc')
            ->first();

        if (!$anchor) {
            return null;
        }

        // Re-query across every conversation for this (account, CONTACT)
        // pair -- not just this conversation -- so a customer who also
        // wrote on a different (newer) conversation still reopens this
        // one's window. "Contact" spans both identities (Stage 5): rows
        // match on the anchor's bsuid OR its phone, whichever are present,
        // so the window stays continuous across the phone -> bsuid
        // transition (pre-username phone-only rows and post-username
        // bsuid-only rows bridge through the both-ids rows between them).
        //
        // A single pass matching only the anchor's OWN identity is not
        // enough to bridge a genuinely phone-only anchor to a later,
        // genuinely bsuid-only row: they share no field in common and are
        // connected only through an intermediate "both-ids" row (one
        // ProcessInboundMessage does write, when a webhook carries both a
        // phone and a bsuid). So this runs in two passes: first collect
        // every phone/bsuid that co-occurs with the anchor's own identity
        // (or, when the anchor itself carries neither, the anchor row
        // alone) on any inbound row for this account; then re-query using
        // that expanded identity set. One expansion pass is sufficient --
        // Stage 5's phone -> bsuid transition produces at most one bridge
        // hop, and nothing in this module ever writes a third, disjoint
        // identity onto the same contact.
        $identityMatch = function ($query) use ($anchor) {
            $matched = false;

            if ($anchor->contact_bsuid) {
                $query->orWhere('contact_bsuid', $anchor->contact_bsuid);
                $matched = true;
            }

            if ($anchor->contact_phone) {
                $query->orWhere('contact_phone', $anchor->contact_phone);
                $matched = true;
            }

            if (!$matched) {
                // Anchor with neither identity (pre-Stage-5 edge rows):
                // the anchor itself is the only row we can honestly
                // attribute to this contact.
                $query->orWhere('id', $anchor->id);
            }
        };

        $bridge = KapsoMessage::where('account_id', $anchor->account_id)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->where($identityMatch)
            ->get(['contact_phone', 'contact_bsuid']);

        $phones = $bridge->pluck('contact_phone')->filter()->unique()->values()->all();
        $bsuids = $bridge->pluck('contact_bsuid')->filter()->unique()->values()->all();

        // Ordered by created_at, not id: created_at is the value that
        // actually decides openness, and this module's own fixtures (and
        // any future backfill) may not insert rows in timestamp order.
        $last = KapsoMessage::where('account_id', $anchor->account_id)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->where(function ($query) use ($anchor, $phones, $bsuids) {
                $matched = false;

                if ($bsuids) {
                    $query->orWhereIn('contact_bsuid', $bsuids);
                    $matched = true;
                }

                if ($phones) {
                    $query->orWhereIn('contact_phone', $phones);
                    $matched = true;
                }

                if (!$matched) {
                    $query->orWhere('id', $anchor->id);
                }
            })
            ->orderBy('created_at', 'desc')
            ->first();

        // Unreachable today: $anchor is itself always among the rows this
        // final query considers -- either its own phone/bsuid landed in
        // $phones/$bsuids (it was one of the rows the bridge query above
        // matched), or neither identity was set and the id fallback catches
        // it directly -- so $last can never come back empty here. Insurance
        // against the null-to-whereNull nuance Eloquent's query builder has
        // around ->where(column, null) versus ->whereNull(column), in case a
        // future refactor of the query above changes that -- fail safe
        // instead of calling ->created_at on null.
        if (!$last) {
            return null;
        }

        $closesAt = $last->created_at->copy()->addHours(self::WINDOW_HOURS);

        return [
            'open'            => $closesAt->isFuture(),
            'last_inbound_at' => $last->created_at->copy(),
            'closes_at'       => $closesAt,
        ];
    }
}

<?php

namespace Modules\KapsoWhatsApp\Services;

use Modules\KapsoWhatsApp\Entities\KapsoMessage;

/**
 * The per-message channel chip (user request 2026-08-02): a small
 * "WhatsApp" tag rendered next to the sender's name -- core's
 * `thread.after_person_action` hook, which fires inside .thread-person
 * right after the name -- on every thread that actually travelled via
 * WhatsApp. The ticks under a reply say what HAPPENED to a WhatsApp
 * message; this chip says WHICH MEDIUM carried it, which is the
 * distinction a mixed (Stage 4) conversation needs at a glance.
 *
 * "Travelled via WhatsApp" = a kapso_whatsapp_messages row references the
 * thread_id: written by inbound processing for customer messages, by the
 * send jobs' claim rows for agent replies and templates, and by the
 * reconciler for foreign sends. Email threads carry NO chip -- email is
 * FreeScout's ambient default, and absence next to a chipped sibling IS
 * the signal; chipping every email in every ordinary conversation would
 * be noise far outside this module's lane.
 */
class ThreadChannelBadge
{
    /**
     * conversation_id => array<thread_id, true>, resolved once per request
     * per conversation: the hook fires for every thread on the page, and
     * one indexed query for the whole conversation beats one per thread.
     * Keyed by conversation id, which is unique per test too (no teardown
     * reset needed -- same reasoning as WindowState::$cache).
     *
     * @var array<int, array<int, true>>
     */
    private static $cache = [];

    /**
     * Tests only: rows never appear mid-render in production, but a test
     * that creates a row AFTER already rendering a thread of the same
     * conversation must drop the request-scoped cache to see it.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    public static function render($thread, $loop, $threads, $conversation, $mailbox): void
    {
        if ((int) $thread->type === \App\Thread::TYPE_NOTE) {
            return;
        }

        if (!array_key_exists($conversation->id, self::$cache)) {
            self::$cache[$conversation->id] = KapsoMessage::where('conversation_id', $conversation->id)
                ->whereNotNull('thread_id')
                ->pluck('thread_id')
                ->flip()
                ->all();
        }

        if (!isset(self::$cache[$conversation->id][$thread->id])) {
            return;
        }

        // 'WhatsApp' is a brand name, not translatable copy.
        echo '<span class="kwa-channel-chip">WhatsApp</span>';
    }
}

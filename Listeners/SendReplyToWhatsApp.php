<?php

namespace Modules\KapsoWhatsApp\Listeners;

use App\Conversation;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Jobs\SendReplyMessage;

/**
 * Hooks core's `chat_conversation.send_reply` action (see
 * app/Listeners/SendReplyToCustomer.php): core fires this -- via
 * \Helper::backgroundAction(), delayed by Conversation::UNDO_TIMOUT -- for
 * every chat-type conversation instead of emailing a reply, regardless of
 * which channel module owns it. This listener is what narrows that down to
 * WhatsApp (KapsoAccount::CHANNEL) conversations and queues a
 * SendReplyMessage job for the triggering reply, once the undo window has
 * passed.
 *
 * $replies is NOT "the replies to send" -- it is the conversation's WHOLE
 * published thread history, newest-first. SendReplyToCustomer.php:41 builds
 * it from Conversation::getThreads(null, null, [TYPE_CUSTOMER, TYPE_MESSAGE,
 * TYPE_LINEITEM]) -- every published thread of those types, not just the new
 * one. Its trim loop at :48-57 only removes threads NEWER than the
 * triggering thread ($event->last_thread / $event->thread), so first() is
 * always the triggering reply and everything behind it is older,
 * already-delivered history kept only for quoting. Core's own email job
 * treats the identical collection the same way
 * (app/Jobs/SendReplyToCustomer.php:129: `$this->last_thread =
 * $this->threads->first();` -- first() is what gets delivered, the rest is
 * quoted context). Dispatching anything beyond first() would re-send old
 * agent replies to the customer's phone on every new reply, so this listener
 * never iterates $replies.
 *
 * $conversation, $replies and $customer are all serialized snapshots taken
 * at the moment the agent hit "send", not live models -- by the time this
 * runs (after the delay), the agent may since have clicked undo. The
 * TYPE_MESSAGE + STATE_PUBLISHED re-check below, against a freshly fetched
 * Thread, is a cheap, best-effort skip that exists purely to keep queue
 * noise down; it is NOT what correctness rests on. SendReplyMessage::guards()
 * performs the exact same re-check again, authoritatively, immediately
 * before it actually sends -- because more time (and so more chance of a
 * further undo) elapses between this listener running and that job
 * executing. Neither check replaces the other; both must stay.
 *
 * The try/catch below protects against a bad/foreign/undone reply at
 * runtime, NOT against a registration mistake: handle() keeps all three
 * parameters required and default-less on purpose -- if the provider ever
 * registered this action with fewer args, PHP would raise an
 * ArgumentCountError while binding the call, before this method body (and so
 * this try/catch) ever runs, and that error would escape straight into
 * core's TriggerAction wrapper. Giving any parameter a default would mask
 * that failure mode instead of surfacing it.
 */
class SendReplyToWhatsApp
{
    public function handle($conversation, $replies, $customer)
    {
        try {
            if (!$conversation instanceof Conversation
                || (int) $conversation->channel !== KapsoAccount::CHANNEL) {
                return;
            }

            $first = $replies->first();

            if (!$first || empty($first->id)) {
                \Log::info('[KapsoWhatsApp] SendReplyToWhatsApp: chat_conversation.send_reply fired with no deliverable reply', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            // Re-fetch: $first is a serialized snapshot taken before the
            // undo window closed, and may not even belong to this
            // conversation. This check only keeps undone or foreign threads
            // out of the queue -- SendReplyMessage::guards() is the
            // authoritative gate (see class docblock above).
            $fresh = Thread::find($first->id);

            if (!$fresh
                || (int) $fresh->conversation_id !== (int) $conversation->id
                || (int) $fresh->type !== Thread::TYPE_MESSAGE
                || (int) $fresh->state !== Thread::STATE_PUBLISHED) {
                return;
            }

            SendReplyMessage::dispatch($fresh->id);
        } catch (\Throwable $e) {
            // A throw here would break the core queue job
            // (\Helper::backgroundAction()) wrapped around this Eventy
            // action -- never let one bad conversation/reply take that down.
            \Log::error('[KapsoWhatsApp] SendReplyToWhatsApp: listener failed', [
                'conversation_id' => is_object($conversation) ? ($conversation->id ?? null) : null,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}

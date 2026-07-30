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
 * runs (after the delay), the agent may since have clicked undo, or the
 * conversation may since have been merged into another one, reassigning
 * this thread's conversation_id (see Conversation::mergeConversations(),
 * app/Conversation.php:1378-1383). The TYPE_MESSAGE + STATE_PUBLISHED +
 * channel re-check below, against a freshly fetched Thread and the
 * conversation it *actually* belongs to right now, is a cheap, best-effort
 * skip that exists purely to keep queue noise down; it is NOT what
 * correctness rests on. It is deliberately a STRICT SUBSET of
 * SendReplyMessage::guards() (Jobs/SendReplyMessage.php:134-196), which
 * re-derives the identical thread -> conversation -> channel chain
 * authoritatively, immediately before it actually sends -- because more
 * time (and so more chance of a further undo or merge) elapses between this
 * listener running and that job executing. This listener must never reject
 * anything the job would still accept: when in doubt, dispatch and let
 * guards() have the final word. Neither check replaces the other; both
 * must stay.
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
                // App\Listeners\SendReplyToCustomer (core) is a plain,
                // non-queued listener (no ShouldQueue) -- it builds $replies
                // synchronously, in the same request that just created the
                // triggering reply thread, and only afterwards hands the
                // whole action off to \Helper::backgroundAction() for
                // delayed execution. There is no queue hop, and so no race
                // window, between "the reply thread exists" and "$replies is
                // computed": an empty-or-id-less first() here is therefore
                // structurally impossible from any of core's normal flows,
                // not a legitimate race. If this branch ever fires, a reply
                // was lost -- logged at error (the default log_level is
                // `error`, which would otherwise discard an info-level line
                // silently).
                \Log::error('[KapsoWhatsApp] SendReplyToWhatsApp: chat_conversation.send_reply fired with no deliverable reply', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            // Re-fetch: $first is a serialized snapshot taken before the
            // undo window closed. This check only keeps undone or
            // no-longer-a-message threads out of the queue --
            // SendReplyMessage::guards() is the authoritative gate (see
            // class docblock above).
            $fresh = Thread::find($first->id);

            if (!$fresh
                || (int) $fresh->type !== Thread::TYPE_MESSAGE
                || (int) $fresh->state !== Thread::STATE_PUBLISHED) {
                return;
            }

            // Do NOT compare $fresh->conversation_id to the snapshot
            // $conversation's id: Conversation::mergeConversations()
            // (app/Conversation.php:1378-1383) reassigns a thread's
            // conversation_id to the surviving conversation inside the very
            // undo window this listener waits out, so a merge of one
            // WhatsApp conversation into another leaves $conversation stale
            // while $fresh->conversation_id is correct. Re-derive the
            // channel from the thread's OWN, current conversation instead --
            // exactly what SendReplyMessage::guards() does
            // (Jobs/SendReplyMessage.php:150-156) -- so this guard can only
            // ever be a subset of the job's: it never rejects a thread the
            // job would still accept.
            $freshConversation = Conversation::find($fresh->conversation_id);

            if (!$freshConversation || (int) $freshConversation->channel !== KapsoAccount::CHANNEL) {
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

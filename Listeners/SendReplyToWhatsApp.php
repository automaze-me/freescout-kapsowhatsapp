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
 * WhatsApp (KapsoAccount::CHANNEL) conversations and queues one
 * SendReplyMessage job per reply thread still eligible once the undo window
 * has passed.
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
 */
class SendReplyToWhatsApp
{
    public function handle($conversation, $replies, $customer)
    {
        try {
            if (!$conversation instanceof Conversation || (int) $conversation->channel !== KapsoAccount::CHANNEL) {
                return;
            }

            foreach ($replies as $reply) {
                $fresh = Thread::find($reply->id ?? 0);

                if (!$fresh || (int) $fresh->type !== Thread::TYPE_MESSAGE || (int) $fresh->state !== Thread::STATE_PUBLISHED) {
                    continue;
                }

                SendReplyMessage::dispatch($fresh->id);
            }
        } catch (\Throwable $e) {
            // A throw here would break the core queue job
            // (\Helper::backgroundAction()) wrapped around this Eventy
            // action -- never let one bad conversation/reply take that down.
            \Log::error('[KapsoWhatsApp] SendReplyToWhatsApp: listener failed', [
                'conversation_id' => $conversation->id ?? null,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}

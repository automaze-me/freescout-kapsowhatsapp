<?php

namespace Modules\KapsoWhatsApp\Listeners;

use App\Conversation;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\SendReplyMessage;
use Modules\KapsoWhatsApp\Services\ChannelChoice;

/**
 * Stage 4's defer-or-intercept pair -- see "Stage 4: per-reply channel
 * selection" -> "Architecture: defer-or-intercept" / "Capture" in
 * dev-notes/specs/2026-07-28-kapso-whatsapp-design.md. All static, no
 * constructor state, mirroring SendReplyToWhatsApp's stateless-listener
 * shape (that one is registered as an instance method purely because Eventy
 * needs a bound callable and it predates this convention; capture()/
 * intercept() are registered directly as `[self::class, 'method']`).
 *
 * capture() writes the agent's choice onto thread.meta at request time;
 * intercept() reads it back, later, inside core's own
 * `conversation.skip_send_reply_to_customer` filter and decides whether core
 * proceeds natively or gets short-circuited into the OTHER channel's send
 * path. The two never run in the same call stack -- capture() fires during
 * the HTTP request that creates the reply, intercept() fires moments later
 * when core's SendReplyToCustomer listener runs (app/Listeners/
 * SendReplyToCustomer.php:78) -- so intercept() trusts nothing about
 * capture() except what it already persisted to the thread's meta column.
 */
class RouteReplyChannel
{
    /**
     * Hooked at core's `thread.before_save_from_request` action
     * (ConversationsController.php:1187 and :1335 -- both call sites assign
     * $thread->type BEFORE firing this hook and save()/push() the thread
     * immediately AFTER, so setMeta() here needs no save() of its own; see
     * the Task 2 report for the empirical verification of that ordering).
     *
     * On a non-TYPE_MESSAGE thread (TYPE_NOTE, TYPE_CUSTOMER, ...) this
     * bails writing NOTHING -- never even a clear -- because such a thread
     * never carries this meta key in the first place: a note is never sent
     * to the customer on any channel, so a channel choice on one is
     * meaningless.
     *
     * On a TYPE_MESSAGE thread, meta is written only when ALL of: the
     * posted `kwa_channel` value is exactly one of
     * ChannelChoice::CHANNEL_WHATSAPP / CHANNEL_EMAIL (never coerced --
     * 'sms', an array, or a missing field are all rejected outright); and
     * ChannelChoice says that specific channel is actually available on the
     * thread's conversation (a forged/stale field can never park an
     * unreachable channel in meta). Anything else -- absent, invalid, or
     * unavailable -- explicitly CLEARS the meta key (whole-stage review,
     * F4) rather than leaving whatever was already there. Two things
     * depend on the clear, not a bare no-op: an agent re-sending after Undo
     * must not silently inherit an earlier choice made on that same thread
     * row (absence = native must hold on re-sends); and core's own
     * multi-recipient copy path (ConversationsController.php:1316)
     * replicates $thread -- meta included -- into $thread_copy BEFORE this
     * hook re-fires on the copy at :1335, so an invalid/unavailable choice
     * on the copy must erase the value it inherited from the original
     * thread rather than silently keep it. Absence of meta afterwards means
     * "native": intercept() below then leaves core entirely untouched.
     */
    public static function capture(Thread $thread, $request)
    {
        if ((int) $thread->type !== Thread::TYPE_MESSAGE) {
            return;
        }

        $value = $request->input('kwa_channel');

        if ($value === ChannelChoice::CHANNEL_WHATSAPP || $value === ChannelChoice::CHANNEL_EMAIL) {
            $conversation = Conversation::find($thread->conversation_id);

            $available = $conversation && ($value === ChannelChoice::CHANNEL_WHATSAPP
                ? ChannelChoice::whatsappAvailable($conversation)
                : ChannelChoice::emailAvailable($conversation));

            if ($available) {
                $thread->setMeta(KapsoMessage::THREAD_META_CHANNEL, $value);

                return;
            }
        }

        $thread->setMeta(KapsoMessage::THREAD_META_CHANNEL, null);
    }

    /**
     * The thread's captured choice, when it is one of the two valid
     * constants; null otherwise (including "no meta at all"), which
     * intercept() below treats as "behave natively".
     */
    public static function effectiveChannel(Thread $thread): ?string
    {
        $value = $thread->getMeta(KapsoMessage::THREAD_META_CHANNEL);

        return $value === ChannelChoice::CHANNEL_WHATSAPP || $value === ChannelChoice::CHANNEL_EMAIL
            ? $value
            : null;
    }

    /**
     * Hooked at core's `conversation.skip_send_reply_to_customer` filter
     * (app/Listeners/SendReplyToCustomer.php:78 -- fires AFTER $replies is
     * narrowed to TYPE_CUSTOMER/TYPE_MESSAGE, BEFORE the isChat() branch, so
     * it can intercept both directions before core commits to either path).
     * $replies is core's WHOLE published thread history, newest-first --
     * exactly the collection SendReplyToWhatsApp documents at length -- so
     * $replies->first() is always the triggering reply and this method must
     * never look past it (the same replay hazard: dispatching an older
     * thread would re-send already-delivered history).
     *
     * Four cells:
     *   - $skip already true (another module vetoed) -> return it unchanged;
     *     never un-veto something else already decided to stop.
     *   - no triggering thread, or it carries no valid captured choice ->
     *     return $skip (false): native, core proceeds untouched.
     *   - the choice equals the conversation's own native channel
     *     ('whatsapp' on a channel-105 conversation, 'email' on any other)
     *     -> return $skip: also native, nothing to intercept.
     *   - otherwise, a genuine cross-channel choice -> dispatch the OTHER
     *     channel's send job ourselves and return true, so core's own
     *     dispatch never also fires for this thread.
     */
    public static function intercept($skip, Conversation $conversation, $replies)
    {
        if ($skip) {
            return $skip;
        }

        $thread = $replies->first();

        if (!$thread) {
            return $skip;
        }

        $channel = self::effectiveChannel($thread);

        if ($channel === null) {
            return $skip;
        }

        $native = (int) $conversation->channel === KapsoAccount::CHANNEL
            ? ChannelChoice::CHANNEL_WHATSAPP
            : ChannelChoice::CHANNEL_EMAIL;

        if ($channel === $native) {
            return $skip;
        }

        if ($channel === ChannelChoice::CHANNEL_WHATSAPP) {
            // Unlike SendReplyToWhatsApp (dispatched from inside core's
            // already-delayed \Helper::backgroundAction() callback), this
            // filter runs synchronously, inline with the HTTP request that
            // created the reply -- so the undo delay has to be applied here,
            // explicitly, to preserve the same undo window a native chat
            // reply gets.
            SendReplyMessage::dispatch($thread->id)
                ->delay(now()->addSeconds(Conversation::UNDO_TIMOUT));
        } else {
            // F2 (whole-stage review, IMPORTANT): a conversation born on
            // WhatsApp keeps conversations.customer_email = '' forever --
            // nothing backfills a chat conversation's own column when an
            // email is added to the customer later. Left as '', core's mail
            // job dispatches to an empty address, Swift throws an
            // un-retried RFC address exception, and the send dies as
            // SEND_ERROR with no chance to retry. Backfilled here, once,
            // right before the dispatch that is about to need it --
            // mirrors core's own phone->email conversion backfill elsewhere
            // (e.g. Customer::mergeWith()) rather than inventing a new
            // pattern.
            if (!$conversation->customer_email && $conversation->customer) {
                $conversation->customer_email = $conversation->customer->getMainEmail();
                $conversation->save();
            }

            // Replicates core's own dispatch verbatim
            // (SendReplyToCustomer.php:110-112). The reply-to-other-customer
            // nuance core applies there (thread->getToArray()) resolves to
            // the same recipient here rather than not arising at all: a
            // chat reply's To -- set whenever conversation.customer_email is
            // non-empty (ConversationsController.php:1015) -- is the
            // conversation customer's own address, so core's override
            // either does not trigger or lands on the same customer
            // (whole-stage review, F9).
            $delay = \Eventy::filter(
                'conversation.send_reply_to_customer_delay',
                now()->addSeconds(Conversation::UNDO_TIMOUT),
                $conversation,
                $replies
            );

            \App\Jobs\SendReplyToCustomer::dispatch($conversation, $replies, $conversation->customer)
                ->delay($delay)
                ->onQueue('emails');
        }

        return true;
    }
}

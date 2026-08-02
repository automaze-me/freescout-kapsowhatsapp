<?php

namespace Modules\KapsoWhatsApp\Entities;

use Illuminate\Database\Eloquent\Model;

class KapsoMessage extends Model
{
    const DIRECTION_INBOUND  = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    /**
     * Thread::meta key set on the TYPE_LINEITEM thread ReconcileOutboundMessage
     * creates for a WhatsApp delivery failure. Core's Thread::getActionText()
     * has no branch for a line item with a NULL action_type (this module's
     * failure line items never set one), so it would otherwise render as an
     * empty bar with no visible text. KapsoWhatsAppServiceProvider hooks
     * core's `thread.action_text` Eventy filter and checks this meta key to
     * recognise "this is our own line item" and return its body text instead.
     */
    const LINEITEM_META_DELIVERY_FAILED = 'kapsowhatsapp_delivery_failed';

    /**
     * Thread::meta key set on the reply thread SendReplyMessage created, once
     * ReconcileOutboundMessage sees a matching `whatsapp.message.sent`
     * webhook. Presence alone renders the "Sent via WhatsApp" marker -- see
     * KapsoWhatsAppServiceProvider's `thread.meta` action -- the ISO-8601
     * timestamp value itself is not rendered, kept only for debugging.
     *
     * The true invariant: present if and only if every part of the reply
     * Kapso has reported on so far succeeded, and none failed. A failure
     * recorded for the thread -- any of its rows going `status = 'failed'`
     * or `send_state = SEND_STATE_FAILED` -- clears this meta
     * (ReconcileOutboundMessage::applyFailureToRow()) and blocks any future
     * stamp for as long as that failure stands: the marker's meaning is
     * "this reply is delivered-and-healthy", not merely "Kapso accepted this
     * part", so it must never coexist with a recorded failure for the same
     * reply. It can be (re-)stamped only by
     * ReconcileOutboundMessage::markOwnSendSent(), and only while the thread
     * has no failed part at the moment it runs.
     *
     * Accepted residual: this still assumes a single worker (the only
     * supported FreeScout configuration -- core's own Kernel.php actively
     * kills extra worker processes). With 2+ workers, a concurrent
     * sent(part A) / failed(part B) pair for the same multi-part reply can
     * interleave markOwnSendSent()'s sibling-check and applyFailureToRow()'s
     * claim-and-clear such that the sibling check runs, finds nothing failed
     * yet, and stamps the marker *after* the failure's own clear has already
     * run -- leaving the marker standing next to a recorded failure. Same
     * accepted-residue category as the wamid-crash double-send window
     * documented on SendReplyMessage's own class docblock, not something
     * either method's own claim/gate can rule out on its own.
     */
    const THREAD_META_SENT_AT = 'kapsowhatsapp_sent_at';

    /**
     * Thread::meta key Stage 4's RouteReplyChannel::capture() writes on a
     * reply thread (TYPE_MESSAGE only) to record the agent's per-reply
     * channel choice -- values are exactly ChannelChoice::CHANNEL_WHATSAPP /
     * CHANNEL_EMAIL, never anything else (capture() only ever writes one of
     * the two, and only when ChannelChoice says that channel is available on
     * the conversation). Absence means "native": the reply behaves exactly
     * as it always has, whether or not this module is even active. See
     * "Stage 4: per-reply channel selection" in
     * dev-notes/specs/2026-07-28-kapso-whatsapp-design.md.
     */
    const THREAD_META_CHANNEL = 'kapsowhatsapp_channel';

    /**
     * Thread::meta key holding the customer's current reaction emoji for
     * the message this thread carries (single slot: WhatsApp allows one
     * reaction per user per message; a new one replaces it, an empty one
     * removes it). Rendered as a remark under the message via the
     * `thread.meta` hook -- never written into the thread body, where it
     * read as part of the message text (user feedback 2026-08-02).
     */
    const THREAD_META_REACTION = 'kapsowhatsapp_reaction';

    const SEND_STATE_SENDING  = 'sending';
    const SEND_STATE_ACCEPTED = 'accepted';
    const SEND_STATE_FAILED   = 'failed';

    const PART_BODY = 'body';

    /**
     * part_key for a Stage 3c template send: one claim row per template
     * message (a template send is never chunked or captioned the way a
     * regular reply's body/attachment parts are), on the same
     * (thread_id, part_key) unique index every other part uses.
     */
    const PART_TEMPLATE = 'tpl';

    protected $table = 'kapso_whatsapp_messages';

    protected $fillable = [
        'account_id', 'conversation_id', 'thread_id', 'attachment_id', 'wamid',
        'kapso_conversation_id', 'direction', 'status', 'is_reaction', 'contact_phone', 'error',
    ];

    /**
     * Without this, reading `events_dispatched_at` back off the model would
     * yield a raw string instead of a Carbon instance.
     */
    protected $dates = ['events_dispatched_at'];

    protected $casts = ['is_reaction' => 'boolean'];

    public function account()
    {
        return $this->belongsTo(KapsoAccount::class, 'account_id');
    }

    public static function seen($wamid)
    {
        return $wamid ? self::where('wamid', $wamid)->exists() : false;
    }

    public static function threadForWamid($wamid)
    {
        if (!$wamid) {
            return null;
        }

        $threadId = self::where('wamid', $wamid)->value('thread_id');

        return $threadId === null ? null : (int) $threadId;
    }

    public static function partKeyForBodyChunk($i)
    {
        return $i === 0 ? self::PART_BODY : self::PART_BODY.':'.($i + 1);
    }

    public static function partKeyForAttachment($attachmentId)
    {
        return 'att:'.$attachmentId;
    }

    /**
     * Whether the core FreeScout events (CustomerCreatedConversation /
     * CustomerReplied and the matching Eventy hooks) have been confirmed
     * dispatched for this (inbound-only) message. `events_dispatched_at` is
     * intentionally excluded from `$fillable` — the only writer is the
     * atomic `UPDATE ... WHERE events_dispatched_at IS NULL` claim in
     * `ProcessInboundMessage::dispatchPendingEvents()`, never mass
     * assignment or a read-then-`save()` pattern, which would not be safe
     * against concurrent workers or retry-after-listener-throw.
     */
    public function eventsDispatched(): bool
    {
        return (bool) $this->events_dispatched_at;
    }
}

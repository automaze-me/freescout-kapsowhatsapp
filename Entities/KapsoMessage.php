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
     * ReconcileOutboundMessage sees the matching `whatsapp.message.sent`
     * webhook for that thread's own accepted send. Presence alone renders the
     * "Sent via WhatsApp" marker -- see KapsoWhatsAppServiceProvider's
     * `thread.meta` action -- the ISO-8601 timestamp value itself is not
     * rendered, kept only for debugging. Never set on a row that is, or later
     * becomes, `failed`: see ReconcileOutboundMessage's own docblock for why
     * a `sent` event can never win against a `failed` one.
     */
    const THREAD_META_SENT_AT = 'kapsowhatsapp_sent_at';

    const SEND_STATE_SENDING  = 'sending';
    const SEND_STATE_ACCEPTED = 'accepted';
    const SEND_STATE_FAILED   = 'failed';

    const PART_BODY = 'body';

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
     * Timestamp of the most recent inbound message, which opens the 24h
     * customer-service window. Stage 3 consumes this; recorded from Stage 1
     * so the data is present when the window logic lands.
     */
    public static function lastInboundAt($conversationId)
    {
        return self::where('conversation_id', $conversationId)
            ->where('direction', self::DIRECTION_INBOUND)
            ->max('created_at');
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

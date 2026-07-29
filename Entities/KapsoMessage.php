<?php

namespace Modules\KapsoWhatsApp\Entities;

use Illuminate\Database\Eloquent\Model;

class KapsoMessage extends Model
{
    const DIRECTION_INBOUND  = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    protected $table = 'kapso_whatsapp_messages';

    protected $fillable = [
        'account_id', 'conversation_id', 'thread_id', 'attachment_id', 'wamid',
        'kapso_conversation_id', 'direction', 'status', 'contact_phone', 'error',
    ];

    /**
     * Without this, reading `events_dispatched_at` back off the model would
     * yield a raw string instead of a Carbon instance.
     */
    protected $dates = ['events_dispatched_at'];

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

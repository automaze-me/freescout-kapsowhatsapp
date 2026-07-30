<?php

namespace Modules\KapsoWhatsApp\Services;

use App\Conversation;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;

/**
 * The red "WhatsApp delivery failed" line item, shared by the two places a
 * send can die: delivery-time (ReconcileOutboundMessage, Kapso's
 * message.failed) and request-time (SendReplyMessage, after its retries are
 * exhausted). One writer keeps the rendering contract single:
 * LINEITEM_META_DELIVERY_FAILED + the provider's thread.action_text filter.
 */
class DeliveryFailureLineItem
{
    public static function create(Conversation $conversation, $summary)
    {
        $lineItem = new Thread();
        $lineItem->conversation_id = $conversation->id;
        $lineItem->user_id         = null;
        $lineItem->type            = Thread::TYPE_LINEITEM;
        $lineItem->status          = Thread::STATUS_NOCHANGE;
        $lineItem->state           = Thread::STATE_PUBLISHED;
        // action_type is deliberately left NULL: core's ACTION_TYPE_* set has
        // no "WhatsApp delivery failed" member, and there is no core hook to
        // register a new one. body still carries the fully-translated,
        // escaped text, which is what actually needs to reach the page — see
        // the LINEITEM_META_DELIVERY_FAILED meta flag below and
        // KapsoWhatsAppServiceProvider's `thread.action_text` filter, which is
        // what makes core render this body instead of an empty action-text
        // bar (getActionText() has no fallback for a NULL action_type).
        // Wrapped in a `text-danger` span so the item actually renders red,
        // not merely visible: thread.blade.php passes getActionText()'s
        // return through core's safe_raw_html() (Helper::stripDangerousTags()),
        // whose denylist is script/form/iframe/link/object/meta/embed/applet/
        // style — `<span class="...">` is not on it and survives untouched.
        $lineItem->body            = '<span class="text-danger">'.__('WhatsApp delivery failed:').' '.e($summary).'</span>';
        // Core defines only PERSON_CUSTOMER and PERSON_USER — there is no
        // PERSON_SYSTEM. A system-generated line item is attributed to the user side.
        $lineItem->source_via      = Thread::PERSON_USER;
        $lineItem->source_type     = Thread::SOURCE_TYPE_API;
        $lineItem->customer_id     = $conversation->customer_id;
        $lineItem->setMeta(KapsoMessage::LINEITEM_META_DELIVERY_FAILED, true);
        $lineItem->save();

        return $lineItem;
    }
}

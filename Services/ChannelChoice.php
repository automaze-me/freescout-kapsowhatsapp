<?php

namespace Modules\KapsoWhatsApp\Services;

use App\Conversation;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;

/**
 * Stage 4's single authority on per-reply channel availability and the
 * follow-the-customer default -- see "Stage 4: per-reply channel selection"
 * in dev-notes/specs/2026-07-28-kapso-whatsapp-design.md. Every method is
 * static, and none of them cache: call sites are few and every query below
 * is an indexed lookup, so a static cache would only import the same
 * test-leak surface WindowState::$cache exists to avoid, for nothing.
 *
 * "Available" here is conversation-scoped, never customer-scoped: a
 * customer who also has a WhatsApp thread on a DIFFERENT conversation does
 * not make WhatsApp available on this one (an explicit Stage 4 decision --
 * see the spec's "User decisions"). This mirrors ChannelChoice's own
 * whatsappAvailable() query (conversation_id-scoped), which deliberately
 * does NOT reuse WindowState's cross-conversation (account, contact_phone)
 * reopen logic for that reason -- these are different questions with
 * different scopes answering to the same design intent.
 */
class ChannelChoice
{
    const CHANNEL_WHATSAPP = 'whatsapp';
    const CHANNEL_EMAIL    = 'email';

    /**
     * At least one inbound `kapso_whatsapp_messages` row exists for this
     * conversation -- the same fact every send job's guards() and
     * TemplatesController::resolveConversation() already derive
     * account/phone from. True for every channel-102 conversation by
     * construction (Stage 1: a channel-102 conversation is only ever
     * created off an inbound webhook) and, since Stage 4 generalises the
     * rest of the module off this same predicate, also true for any
     * channel-1 (or other) conversation a WhatsApp message has ever landed
     * on (Decision D6: inbound appends to the customer's open conversation
     * regardless of its channel).
     */
    public static function whatsappAvailable(Conversation $conversation): bool
    {
        return KapsoMessage::where('conversation_id', $conversation->id)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->exists();
    }

    /**
     * The conversation has a customer, and that customer has at least one
     * email on file (Customer::getMainEmail(), app/Customer.php:492 --
     * returns '' rather than null when there is none, hence the explicit
     * non-empty check rather than a truthiness cast).
     */
    public static function emailAvailable(Conversation $conversation): bool
    {
        $customer = $conversation->customer;

        return $customer && $customer->getMainEmail() !== '';
    }

    /**
     * "Follow the customer": WhatsApp iff the conversation's newest
     * TYPE_CUSTOMER thread carries an inbound kapso row (matched on
     * thread_id, not merely "this conversation has WhatsApp history
     * somewhere") AND the 24h window is currently open for it. Otherwise
     * email, when the customer has one on file. Otherwise the
     * conversation's own native channel -- the last resort, reached only
     * when neither a live WhatsApp window nor an email address exists at
     * all.
     */
    public static function defaultChannel(Conversation $conversation): string
    {
        $newestCustomerThread = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_CUSTOMER)
            ->orderByDesc('id')
            ->first();

        if ($newestCustomerThread) {
            $newestIsWhatsApp = KapsoMessage::where('thread_id', $newestCustomerThread->id)
                ->where('direction', KapsoMessage::DIRECTION_INBOUND)
                ->exists();

            if ($newestIsWhatsApp) {
                $state = WindowState::forConversation($conversation);

                if ($state && $state['open']) {
                    return self::CHANNEL_WHATSAPP;
                }
            }
        }

        if (self::emailAvailable($conversation)) {
            return self::CHANNEL_EMAIL;
        }

        return (int) $conversation->channel === KapsoAccount::CHANNEL ? self::CHANNEL_WHATSAPP : self::CHANNEL_EMAIL;
    }

    /**
     * The picker (Task 3) renders only when an agent genuinely has a choice
     * to make -- both channels reachable for this conversation.
     */
    public static function pickerAvailable(Conversation $conversation): bool
    {
        return self::whatsappAvailable($conversation) && self::emailAvailable($conversation);
    }
}

<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * The per-message channel chip (user request 2026-08-02): a small
 * "WhatsApp" tag next to the sender's name on every thread that actually
 * travelled via WhatsApp, so mixed conversations read unambiguously. A
 * message is WhatsApp-carried iff a kapso_whatsapp_messages row references
 * its thread_id -- the same ground truth everything else in this module
 * derives from. Email threads carry NO chip: email is FreeScout's ambient
 * default, and the absence next to a chipped sibling is the distinction.
 *
 * Rendered at core's `thread.after_person_action` hook
 * (resources/views/conversations/partials/thread.blade.php:102), captured
 * here at hook level per the module's no-page-GET convention.
 */
class ThreadChannelChipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Settings::setApiKey('key');
    }

    protected function makeAccount(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill([
            'name' => 'Support', 'phone_number_id' => '123456789012345',
            'mailbox_id' => $this->testMailbox()->id, 'is_active' => true,
        ]);
        $account->webhook_secret = 'secret';
        $account->save();

        return $account;
    }

    protected function seedInbound(KapsoAccount $account): Conversation
    {
        (new ProcessInboundMessage($account->id, [
            'message' => [
                'id' => 'wamid.chip-seed', 'type' => 'text', 'from' => '4915155555555',
                'text' => ['body' => 'Hi'],
                'kapso' => ['direction' => 'inbound', 'has_media' => false, 'content' => 'Hi'],
            ],
            'conversation' => [
                'id' => 'conv_chip', 'phone_number_id' => '123456789012345',
                'kapso' => ['contact_name' => 'Chip Tester'],
            ],
            'is_new_conversation' => true,
            'phone_number_id'     => '123456789012345',
        ]))->handle();

        return Conversation::findOrFail(
            KapsoMessage::where('wamid', 'wamid.chip-seed')->value('conversation_id')
        );
    }

    protected function makeThread(Conversation $conversation, int $type = Thread::TYPE_MESSAGE): Thread
    {
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = $type;
        $thread->status          = Thread::STATUS_ACTIVE;
        $thread->state           = Thread::STATE_PUBLISHED;
        $thread->body            = 'Body';
        $thread->source_via      = Thread::PERSON_USER;
        $thread->source_type     = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        return $thread;
    }

    protected function renderChip(Thread $thread, Conversation $conversation): string
    {
        ob_start();
        \Eventy::action('thread.after_person_action', $thread, null, collect([$thread]), $conversation, $conversation->mailbox);

        return ob_get_clean();
    }

    public function test_whatsapp_carried_threads_get_the_chip_and_email_threads_do_not()
    {
        $account      = $this->makeAccount();
        $conversation = $this->seedInbound($account);

        // The customer's WhatsApp message (row written by inbound processing).
        $customerThread = Thread::findOrFail(KapsoMessage::where('wamid', 'wamid.chip-seed')->value('thread_id'));
        $this->assertStringContainsString('kwa-channel-chip', $this->renderChip($customerThread, $conversation));

        // An agent reply that went out via WhatsApp (claim row references it).
        $whatsappReply = $this->makeThread($conversation);
        KapsoMessage::create([
            'account_id'      => $account->id,
            'conversation_id' => $conversation->id,
            'thread_id'       => $whatsappReply->id,
            'wamid'           => 'wamid.chip-reply',
            'part_key'        => KapsoMessage::PART_BODY,
            'direction'       => KapsoMessage::DIRECTION_OUTBOUND,
            'send_state'      => KapsoMessage::SEND_STATE_ACCEPTED,
            'contact_phone'   => '+4915155555555',
        ]);
        // The row above was created AFTER the first render already warmed
        // the request-scoped cache -- impossible in production (rows always
        // precede rendering), so the test drops the cache instead.
        \Modules\KapsoWhatsApp\Services\ThreadChannelBadge::clearCache();

        $html = $this->renderChip($whatsappReply, $conversation);
        $this->assertStringContainsString('kwa-channel-chip', $html);
        $this->assertStringContainsString('WhatsApp', $html);

        // An email reply on the SAME (mixed) conversation: no row, no chip.
        $emailReply = $this->makeThread($conversation);
        $this->assertStringNotContainsString('kwa-channel-chip', $this->renderChip($emailReply, $conversation));
    }

    public function test_notes_and_foreign_conversations_never_get_a_chip()
    {
        $account      = $this->makeAccount();
        $conversation = $this->seedInbound($account);

        $note = $this->makeThread($conversation, Thread::TYPE_NOTE);
        $this->assertStringNotContainsString('kwa-channel-chip', $this->renderChip($note, $conversation));

        // A conversation with no WhatsApp history at all: nothing chips,
        // and the per-conversation lookup must not leak across
        // conversations (the WhatsApp conversation above is in the same
        // request-scoped cache).
        $other = new Conversation();
        $other->type        = Conversation::TYPE_EMAIL;
        $other->mailbox_id  = $conversation->mailbox_id;
        $other->folder_id   = $conversation->folder_id;
        $other->customer_id = $conversation->customer_id;
        $other->status      = Conversation::STATUS_ACTIVE;
        $other->state       = Conversation::STATE_PUBLISHED;
        $other->source_via  = Conversation::PERSON_CUSTOMER;
        $other->source_type = Conversation::SOURCE_TYPE_EMAIL;
        $other->subject     = 'Plain email';
        $other->preview     = '';
        $other->save();

        $emailThread = $this->makeThread($other);
        $this->assertStringNotContainsString('kwa-channel-chip', $this->renderChip($emailThread, $other));
    }
}

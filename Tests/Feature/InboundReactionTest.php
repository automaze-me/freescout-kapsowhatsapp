<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Events\CustomerCreatedConversation;
use App\Events\CustomerReplied;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

class InboundReactionTest extends TestCase
{
    protected function makeAccount(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill([
            'name' => 'Support', 'phone_number_id' => '123456789012345',
            'mailbox_id' => $this->testMailbox()->id, 'is_active' => true,
        ]);
        $account->api_key        = 'key';
        $account->webhook_secret = 'secret';
        $account->save();

        return $account;
    }

    protected function textPayload(): array
    {
        return [
            'message' => [
                'id' => 'wamid.target', 'type' => 'text', 'from' => '4915177777777',
                'text' => ['body' => 'Original message'],
                'kapso' => ['direction' => 'inbound', 'has_media' => false, 'content' => 'Original message'],
            ],
            'conversation' => [
                'id' => 'conv_react', 'phone_number_id' => '123456789012345',
                'kapso' => ['contact_name' => 'React Sender'],
            ],
            'is_new_conversation' => true,
            'phone_number_id'     => '123456789012345',
        ];
    }

    protected function reactionPayload(
        string $targetWamid = 'wamid.target',
        string $reactionWamid = 'wamid.reaction',
        string $emoji = '👍'
    ): array {
        return [
            'message' => [
                'id' => $reactionWamid, 'type' => 'reaction', 'from' => '4915177777777',
                'reaction' => ['message_id' => $targetWamid, 'emoji' => $emoji],
                'kapso' => ['direction' => 'inbound', 'has_media' => false, 'content' => $emoji],
            ],
            'conversation' => [
                'id' => 'conv_react', 'phone_number_id' => '123456789012345',
                'kapso' => ['contact_name' => 'React Sender'],
            ],
            'is_new_conversation' => false,
            'phone_number_id'     => '123456789012345',
        ];
    }

    public function test_a_reaction_annotates_the_target_thread_without_creating_one()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->textPayload()))->handle();

        $targetThreadId = KapsoMessage::where('wamid', 'wamid.target')->value('thread_id');
        $conversationId = KapsoMessage::where('wamid', 'wamid.target')->value('conversation_id');
        $threadsBefore  = Conversation::findOrFail($conversationId)->threads()->count();

        (new ProcessInboundMessage($account->id, $this->reactionPayload()))->handle();

        $this->assertSame($threadsBefore, Conversation::findOrFail($conversationId)->threads()->count(),
            'a reaction must not create a new thread');

        $this->assertStringContainsString('👍', Thread::findOrFail($targetThreadId)->body);
        $this->assertSame($targetThreadId, KapsoMessage::where('wamid', 'wamid.reaction')->value('thread_id'));

        // The row-kind marker: the reaction's own KapsoMessage row must be
        // flagged via the dedicated `is_reaction` column, not by overloading
        // `status` (which for ordinary messages carries Kapso's own
        // unvalidated delivery-status string).
        $this->assertTrue((bool) KapsoMessage::where('wamid', 'wamid.reaction')->value('is_reaction'));
    }

    public function test_a_reaction_to_an_unknown_message_is_dropped_quietly()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->reactionPayload('wamid.never-seen')))->handle();

        $this->assertFalse(KapsoMessage::seen('wamid.reaction'),
            'nothing should be recorded for a reaction we cannot place');

        // Asserted directly rather than via a "no conversation subject
        // contains the emoji" proxy: that only worked by fixture
        // coincidence (no subject happens to contain 👍), not because it
        // actually proves nothing was created.
        $this->assertSame(0, Conversation::count(), 'no conversation should have been created');
        $this->assertSame(0, Thread::count(), 'no thread should have been created');
    }

    /**
     * An empty `emoji` means the customer removed their reaction. The
     * previous marker must be stripped from the thread body, not merely
     * overwritten with an empty one that would still leave the wrapper
     * element behind.
     */
    public function test_a_reaction_with_empty_emoji_removes_the_marker()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->textPayload()))->handle();

        $targetThreadId = KapsoMessage::where('wamid', 'wamid.target')->value('thread_id');

        (new ProcessInboundMessage($account->id, $this->reactionPayload('wamid.target', 'wamid.reaction-1', '👍')))->handle();
        $this->assertStringContainsString('kapsowhatsapp-reaction', Thread::findOrFail($targetThreadId)->body);

        (new ProcessInboundMessage($account->id, $this->reactionPayload('wamid.target', 'wamid.reaction-2', '')))->handle();

        $this->assertStringNotContainsString('kapsowhatsapp-reaction', Thread::findOrFail($targetThreadId)->body,
            'an empty-emoji reaction must remove the marker rather than leave it in place');
    }

    /**
     * Known limitation, pinned explicitly: there is only one reaction slot
     * per thread. A second reaction does not accumulate alongside the
     * first — it replaces it.
     */
    public function test_a_second_reaction_replaces_the_first_single_slot_only()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->textPayload()))->handle();

        $targetThreadId = KapsoMessage::where('wamid', 'wamid.target')->value('thread_id');

        (new ProcessInboundMessage($account->id, $this->reactionPayload('wamid.target', 'wamid.reaction-1', '👍')))->handle();
        (new ProcessInboundMessage($account->id, $this->reactionPayload('wamid.target', 'wamid.reaction-2', '😂')))->handle();

        $body = Thread::findOrFail($targetThreadId)->body;

        $this->assertStringNotContainsString('👍', $body,
            'known limitation: only the most recent reaction is kept, a single marker slot per thread');
        $this->assertStringContainsString('😂', $body);
        $this->assertSame(1, substr_count($body, 'kapsowhatsapp-reaction'),
            'only one reaction marker element should ever be present at a time');
    }

    /**
     * A real Kapso payload always names the *original* message in
     * `reaction.message_id`, never a prior reaction. Pin that a second
     * reaction resolves via that original wamid rather than accidentally
     * (or, after some future change, mistakenly) via the first reaction's
     * own KapsoMessage row -- which happens to point at the same thread, so
     * a regression here would not be obvious from the thread body alone.
     */
    public function test_a_repeated_reaction_resolves_via_the_original_messages_wamid()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->textPayload()))->handle();

        $targetThreadId = KapsoMessage::where('wamid', 'wamid.target')->value('thread_id');

        (new ProcessInboundMessage($account->id, $this->reactionPayload('wamid.target', 'wamid.reaction-1', '👍')))->handle();

        $this->assertSame($targetThreadId, KapsoMessage::threadForWamid('wamid.target'),
            'the original message wamid must still resolve to the target thread after a reaction');

        (new ProcessInboundMessage($account->id, $this->reactionPayload('wamid.target', 'wamid.reaction-2', '😂')))->handle();

        $this->assertSame($targetThreadId, KapsoMessage::where('wamid', 'wamid.reaction-2')->value('thread_id'),
            'a repeated reaction must resolve via the original message wamid, not a prior reaction row');

        // The original message's own row is untouched by either reaction:
        // still points at the same thread, and still not itself flagged as
        // a reaction.
        $original = KapsoMessage::where('wamid', 'wamid.target')->first();
        $this->assertSame($targetThreadId, $original->thread_id);
        $this->assertFalse((bool) $original->is_reaction);
    }

    /**
     * Regression test for the vulnerability this fix closes.
     * `kapso_whatsapp_messages.status` is written straight from
     * `$message['kapso']['status']` for ordinary inbound messages -- an
     * unvalidated pass-through of whatever Kapso's webhook payload
     * contains. Before the dedicated `is_reaction` column existed,
     * dispatchPendingEvents() guarded on `status === 'reaction'`, so a
     * genuine, non-reaction message whose kapso.status happened to be the
     * literal string "reaction" would have had its customer events silently
     * swallowed. `is_reaction` is set explicitly by this file's own code and
     * never derived from Kapso's payload, so this must still fire normally.
     */
    public function test_a_genuine_message_with_kapso_status_reaction_still_fires_customer_events()
    {
        $account = $this->makeAccount();

        $payload = $this->textPayload();
        $payload['message']['kapso']['status'] = 'reaction';

        \Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        (new ProcessInboundMessage($account->id, $payload))->handle();

        \Event::assertDispatched(CustomerCreatedConversation::class);

        $this->assertFalse((bool) KapsoMessage::where('wamid', 'wamid.target')->value('is_reaction'));
    }

    /**
     * A reaction is not a new customer message: it must not fire
     * CustomerCreatedConversation/CustomerReplied, which would otherwise
     * trigger notifications/auto-replies for something that isn't one.
     */
    public function test_a_reaction_does_not_fire_customer_events()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->textPayload()))->handle();

        \Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        (new ProcessInboundMessage($account->id, $this->reactionPayload()))->handle();

        \Event::assertNotDispatched(CustomerCreatedConversation::class);
        \Event::assertNotDispatched(CustomerReplied::class);
    }

    /**
     * Redelivery (duplicate webhook, or a queue retry) of an already-recorded
     * reaction wamid takes the generic "$existing" dedupe path near the top
     * of handle(), not the reaction branch itself — that path calls
     * dispatchPendingEvents() unconditionally for any already-seen wamid.
     * Here the reacted-to message is the first thread of its conversation
     * (thread->first === true), the worst case: without a reaction-aware
     * guard in dispatchPendingEvents(), this redelivery would wrongly claim
     * the never-set events_dispatched_at marker and fire
     * CustomerCreatedConversation for a reaction.
     */
    public function test_a_redelivered_reaction_does_not_fire_customer_events()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->textPayload()))->handle();
        (new ProcessInboundMessage($account->id, $this->reactionPayload()))->handle();

        \Event::fake([CustomerCreatedConversation::class, CustomerReplied::class]);

        // Same reaction wamid again.
        (new ProcessInboundMessage($account->id, $this->reactionPayload()))->handle();

        \Event::assertNotDispatched(CustomerCreatedConversation::class);
        \Event::assertNotDispatched(CustomerReplied::class);
    }
}

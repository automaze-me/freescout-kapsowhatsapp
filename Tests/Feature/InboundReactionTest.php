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

    protected function reactionPayload(string $targetWamid = 'wamid.target'): array
    {
        return [
            'message' => [
                'id' => 'wamid.reaction', 'type' => 'reaction', 'from' => '4915177777777',
                'reaction' => ['message_id' => $targetWamid, 'emoji' => '👍'],
                'kapso' => ['direction' => 'inbound', 'has_media' => false, 'content' => '👍'],
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
    }

    public function test_a_reaction_to_an_unknown_message_is_dropped_quietly()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->reactionPayload('wamid.never-seen')))->handle();

        $this->assertFalse(KapsoMessage::seen('wamid.reaction'),
            'nothing should be recorded for a reaction we cannot place');
        $this->assertSame(0, Conversation::where('subject', 'like', '%👍%')->count());
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

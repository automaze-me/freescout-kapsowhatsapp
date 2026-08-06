<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Thread;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoContact;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

class InboundBsuidTest extends TestCase
{
    protected function makeAccount(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill([
            'name'            => 'Support',
            'phone_number_id' => '123456789012345',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ]);
        $account->webhook_secret = 'secret';
        $account->save();

        return $account;
    }

    protected function payload(array $messageOverrides = [], array $conversationOverrides = []): array
    {
        return [
            'message' => array_merge([
                'id'    => 'wamid.bsuid.1',
                'type'  => 'text',
                'text'  => ['body' => 'Hello without a phone'],
                'kapso' => ['direction' => 'inbound', 'content' => 'Hello without a phone'],
            ], $messageOverrides),
            'conversation' => array_merge([
                'id'              => 'conv_bsuid_1',
                'phone_number_id' => '123456789012345',
                'kapso'           => ['contact_name' => ''],
            ], $conversationOverrides),
            'phone_number_id' => '123456789012345',
        ];
    }

    public function test_bsuid_only_inbound_creates_customer_conversation_and_thread()
    {
        $account = $this->makeAccount();

        $job = new ProcessInboundMessage($account->id, $this->payload([
            'from_user_id' => 'US.InboundOnly1',
            'username'     => '@nora',
        ]));
        $job->handle();

        $row = KapsoMessage::where('wamid', 'wamid.bsuid.1')->first();
        $this->assertNotNull($row, 'the message must not be dropped');
        $this->assertSame('US.InboundOnly1', $row->contact_bsuid);
        $this->assertNull($row->contact_phone);

        $conversation = Conversation::find($row->conversation_id);
        $this->assertNotNull($conversation);
        $this->assertSame('@nora', $conversation->customer->first_name);

        $thread = Thread::find($row->thread_id);
        $this->assertNotNull($thread);
        $this->assertStringContainsString('Hello without a phone', $thread->body);

        $this->assertSame(
            $conversation->customer_id,
            (int) KapsoContact::where('bsuid', 'US.InboundOnly1')->value('customer_id')
        );
    }

    public function test_inbound_with_both_identities_stores_both_and_backfills()
    {
        $account = $this->makeAccount();

        $job = new ProcessInboundMessage($account->id, $this->payload([
            'id'           => 'wamid.bsuid.2',
            'from'         => '4915177777701',
            'from_user_id' => 'US.InboundBoth1',
        ]));
        $job->handle();

        $row = KapsoMessage::where('wamid', 'wamid.bsuid.2')->first();
        $this->assertSame('+4915177777701', $row->contact_phone);
        $this->assertSame('US.InboundBoth1', $row->contact_bsuid);

        $this->assertNotNull(KapsoContact::where('bsuid', 'US.InboundBoth1')->first());
    }

    public function test_inbound_without_any_identity_is_dropped()
    {
        $account = $this->makeAccount();

        $job = new ProcessInboundMessage($account->id, $this->payload([
            'id' => 'wamid.bsuid.3',
        ]));
        $job->handle();

        $this->assertNull(KapsoMessage::where('wamid', 'wamid.bsuid.3')->first());
        $this->assertSame(0, Conversation::whereNotNull('id')->where('channel', KapsoAccount::CHANNEL)->count());
    }

    public function test_second_bsuid_only_message_appends_to_the_same_conversation()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload([
            'id'           => 'wamid.bsuid.4a',
            'from_user_id' => 'US.InboundRepeat1',
        ])))->handle();

        (new ProcessInboundMessage($account->id, $this->payload([
            'id'           => 'wamid.bsuid.4b',
            'from_user_id' => 'US.InboundRepeat1',
            'text'         => ['body' => 'Second message'],
        ])))->handle();

        $first  = KapsoMessage::where('wamid', 'wamid.bsuid.4a')->first();
        $second = KapsoMessage::where('wamid', 'wamid.bsuid.4b')->first();

        $this->assertSame($first->conversation_id, $second->conversation_id);
    }

    public function test_reaction_from_a_phoneless_customer_lands_on_the_target_thread()
    {
        $account = $this->makeAccount();

        (new ProcessInboundMessage($account->id, $this->payload([
            'id'           => 'wamid.bsuid.5a',
            'from_user_id' => 'US.InboundReact1',
        ])))->handle();

        (new ProcessInboundMessage($account->id, $this->payload([
            'id'           => 'wamid.bsuid.5b',
            'type'         => 'reaction',
            'from_user_id' => 'US.InboundReact1',
            'reaction'     => ['message_id' => 'wamid.bsuid.5a', 'emoji' => '👍'],
        ])))->handle();

        $target = KapsoMessage::where('wamid', 'wamid.bsuid.5a')->first();
        $thread = Thread::find($target->thread_id);
        $this->assertSame('👍', $thread->getMeta(KapsoMessage::THREAD_META_REACTION));

        $reactionRow = KapsoMessage::where('wamid', 'wamid.bsuid.5b')->first();
        $this->assertNotNull($reactionRow);
        $this->assertSame('US.InboundReact1', $reactionRow->contact_bsuid);
        $this->assertNull($reactionRow->contact_phone);
    }
}

<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Thread;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\SendReplyMessage;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

class SendReplyBsuidTest extends TestCase
{
    protected $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Settings::setApiKey('key');
    }

    protected function fakeResponses(array $queue): void
    {
        $this->history = [];
        $stack         = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        KapsoClient::fakeHttp(new Client(['handler' => $stack]));
    }

    protected function jsonBodyOf(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

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

    protected function makeConversation(KapsoAccount $account): Conversation
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Paula', 'last_name' => 'Phoneless']);
        $customer->addChannel(KapsoAccount::CHANNEL, 'US.SendReply1');

        $folder = Folder::where('mailbox_id', $account->mailbox_id)
            ->where('type', Folder::TYPE_UNASSIGNED)
            ->first();

        $conversation = new Conversation();
        $conversation->type        = Conversation::TYPE_CHAT;
        $conversation->channel     = KapsoAccount::CHANNEL;
        $conversation->mailbox_id  = $account->mailbox_id;
        $conversation->folder_id   = $folder->id;
        $conversation->customer_id = $customer->id;
        $conversation->status      = Conversation::STATUS_ACTIVE;
        $conversation->state       = Conversation::STATE_PUBLISHED;
        $conversation->source_via  = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_API;
        $conversation->subject     = 'WhatsApp chat';
        $conversation->preview     = '';
        $conversation->save();

        return $conversation;
    }

    protected function seedBsuidInbound(KapsoAccount $account, Conversation $conversation, ?string $bsuid = 'US.SendReply1'): KapsoMessage
    {
        $inbound = new KapsoMessage();
        $inbound->account_id      = $account->id;
        $inbound->conversation_id = $conversation->id;
        $inbound->direction       = KapsoMessage::DIRECTION_INBOUND;
        $inbound->wamid           = 'wamid.IN.bsuid1';
        $inbound->contact_phone   = null;
        $inbound->contact_bsuid   = $bsuid;
        $inbound->status          = 'received';
        $inbound->save();

        return $inbound;
    }

    protected function makeReplyThread(Conversation $conversation): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->body               = '<p>On its way.</p>';
        $thread->customer_id        = $conversation->customer_id;
        $thread->save();

        return $thread;
    }

    public function test_send_address_prefers_phone_then_bsuid_then_null()
    {
        $row = new KapsoMessage();
        $row->contact_phone = '+4915177777702';
        $row->contact_bsuid = 'US.AddressBoth1';
        $this->assertSame(['to' => '4915177777702'], $row->sendAddress());

        $row->contact_phone = null;
        $this->assertSame(['recipient' => 'US.AddressBoth1'], $row->sendAddress());

        $row->contact_bsuid = 'not-a-bsuid';
        $this->assertNull($row->sendAddress());

        $row->contact_bsuid = null;
        $this->assertNull($row->sendAddress());
    }

    public function test_reply_to_a_bsuid_only_contact_uses_the_recipient_field()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $inbound      = $this->seedBsuidInbound($account, $conversation);
        $thread       = $this->makeReplyThread($conversation);

        // One send + the best-effort mark-read for the inbound message.
        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT.bsuid1']]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $body = $this->jsonBodyOf(0);
        $this->assertSame('US.SendReply1', $body['recipient']);
        $this->assertArrayNotHasKey('to', $body);
        $this->assertSame('text', $body['type']);

        $row = KapsoMessage::where('wamid', 'wamid.OUT.bsuid1')->first();
        $this->assertNotNull($row);
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
        $this->assertSame('US.SendReply1', $row->contact_bsuid);
        $this->assertNull($row->contact_phone);
    }

    public function test_reply_with_no_usable_address_fails_cleanly_without_an_http_call()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedBsuidInbound($account, $conversation, 'not-a-bsuid');
        $thread = $this->makeReplyThread($conversation);

        $this->fakeResponses([]); // any HTTP call would throw (empty mock queue)

        (new SendReplyMessage($thread->id))->handle();

        $this->assertCount(0, $this->history, 'no HTTP call may be attempted');

        $lineItem = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($lineItem, 'the agent must see a red failure line item');
    }
}

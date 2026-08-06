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
use Modules\KapsoWhatsApp\Jobs\SendTemplateMessage;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

class SendTemplateBsuidTest extends TestCase
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
        $customer->addChannel(KapsoAccount::CHANNEL, 'US.SendTpl1');

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

    protected function seedBsuidInbound(KapsoAccount $account, Conversation $conversation, ?string $bsuid = 'US.SendTpl1'): KapsoMessage
    {
        $inbound = new KapsoMessage();
        $inbound->account_id      = $account->id;
        $inbound->conversation_id = $conversation->id;
        $inbound->direction       = KapsoMessage::DIRECTION_INBOUND;
        $inbound->wamid           = 'wamid.IN.tpl1';
        $inbound->contact_phone   = null;
        $inbound->contact_bsuid   = $bsuid;
        $inbound->status          = 'received';
        $inbound->save();

        return $inbound;
    }

    protected function makeTemplateThread(Conversation $conversation): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->body               = '<p>Template: order_update</p>';
        $thread->customer_id        = $conversation->customer_id;
        $thread->save();

        return $thread;
    }

    public function test_template_send_to_a_bsuid_only_contact_uses_the_recipient_field()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedBsuidInbound($account, $conversation);
        $thread = $this->makeTemplateThread($conversation);

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT.tpl1']]])),
        ]);

        (new SendTemplateMessage($thread->id, 'order_update', 'en_US', ['Bestellung 42']))->handle();

        $body = $this->jsonBodyOf(0);
        $this->assertSame('US.SendTpl1', $body['recipient']);
        $this->assertArrayNotHasKey('to', $body);
        $this->assertSame('template', $body['type']);
        $this->assertSame('order_update', $body['template']['name']);

        $row = KapsoMessage::where('wamid', 'wamid.OUT.tpl1')->first();
        $this->assertSame('US.SendTpl1', $row->contact_bsuid);
        $this->assertSame(KapsoMessage::PART_TEMPLATE, $row->part_key);
    }

    public function test_template_send_with_no_usable_address_fails_cleanly()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedBsuidInbound($account, $conversation, 'not-a-bsuid');
        $thread = $this->makeTemplateThread($conversation);

        $this->fakeResponses([]);

        (new SendTemplateMessage($thread->id, 'order_update', 'en_US', []))->handle();

        $this->assertCount(0, $this->history);
        $this->assertNotNull(
            Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->orderByDesc('id')->first()
        );
    }
}

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

/**
 * Task 2 of Stage 3c: SendTemplateMessage -- the queued job that delivers one
 * approved-template thread through the same send-once claim/wamid/failure
 * machinery SendReplyMessage established for ordinary replies, keyed on the
 * new KapsoMessage::PART_TEMPLATE ('tpl') part. The fixture idiom (account /
 * conversation / seeded inbound row for account+phone derivation) is copied
 * from SendReplyTest -- see that file's docblocks for the rationale behind
 * each fixture shape choice.
 */
class SendTemplateTest extends TestCase
{
    protected $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The API key is a module-wide setting, not a per-account attribute.
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
        $customer = Customer::createWithoutEmail(['first_name' => 'Wanda', 'last_name' => 'WhatsApp']);
        $customer->addChannel(KapsoAccount::CHANNEL, '491771234567');

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

    protected function seedInbound(KapsoAccount $account, Conversation $conversation, string $wamid = 'wamid.IN1'): KapsoMessage
    {
        $inbound = new KapsoMessage();
        $inbound->account_id      = $account->id;
        $inbound->conversation_id = $conversation->id;
        $inbound->direction       = KapsoMessage::DIRECTION_INBOUND;
        $inbound->wamid           = $wamid;
        // "+"-prefixed, matching what ProcessInboundMessage actually writes
        // (PhoneNumber::toE164()) -- see SendReplyTest::seedInbound() for why
        // a bare-digit fixture here would hide a regression.
        $inbound->contact_phone   = '+491771234567';
        $inbound->status          = 'received';
        $inbound->save();

        return $inbound;
    }

    /**
     * The thread a Task 3 controller will eventually create synchronously
     * (direct `new Thread`, TYPE_MESSAGE + STATE_PUBLISHED) before dispatching
     * this job. This job does not read the thread's body at all -- the
     * template name/language/variables come from the job's own constructor
     * args, not from the thread -- so the body here is just representative
     * substituted text, not asserted on.
     */
    protected function makeTemplateThread(Conversation $conversation, array $overrides = []): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status              = Thread::STATUS_ACTIVE;
        $thread->state                = Thread::STATE_PUBLISHED;
        $thread->body                 = '<p>Hello John, order 12345 shipped</p>';
        $thread->source_via            = Thread::PERSON_USER;
        $thread->source_type           = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id           = $conversation->customer_id;

        foreach ($overrides as $key => $value) {
            $thread->{$key} = $value;
        }

        $thread->save();

        return $thread;
    }

    /**
     * Full working fixture: account, conversation, one inbound message (the
     * window this template is closing/replying past), and one published
     * TYPE_MESSAGE thread standing in for the controller-created template
     * thread.
     *
     * @return array{0: KapsoAccount, 1: Conversation, 2: KapsoMessage, 3: Thread}
     */
    protected function scenario(array $threadOverrides = []): array
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $inbound      = $this->seedInbound($account, $conversation);
        $thread       = $this->makeTemplateThread($conversation, $threadOverrides);

        return [$account, $conversation, $inbound, $thread];
    }

    public function test_a_template_send_posts_the_meta_payload_and_claims_once()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT1']]])),
        ]);

        (new SendTemplateMessage($thread->id, 'order_shipped', 'en_US', ['John', '12345']))->handle();

        // Exactly one HTTP call: no mark-read for a template send (no window
        // to speak of -- see the job's class docblock).
        $this->assertCount(1, $this->history);

        $sendRequest = $this->history[0]['request'];
        $sendBody    = $this->jsonBodyOf(0);

        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'to'                => '491771234567',
            'type'              => 'template',
            'template'          => [
                'name'       => 'order_shipped',
                'language'   => ['code' => 'en_US'],
                'components' => [
                    [
                        'type'       => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => 'John'],
                            ['type' => 'text', 'text' => '12345'],
                        ],
                    ],
                ],
            ],
        ], $sendBody);
        $this->assertStringContainsString(
            'https://api.kapso.ai/meta/whatsapp/v24.0/'.$account->phone_number_id.'/messages',
            (string) $sendRequest->getUri()
        );

        $row = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_TEMPLATE)->firstOrFail();
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
        $this->assertSame('wamid.OUT1', $row->wamid);
        // Verbatim "+"-prefixed E.164, same invariant as SendReplyMessage's
        // claim row (see SendReplyTest::test_a_text_reply_is_sent_and_its_wamid_recorded).
        $this->assertSame('+491771234567', $row->contact_phone);
    }

    public function test_no_variables_means_no_components_key()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT1']]])),
        ]);

        (new SendTemplateMessage($thread->id, 'thanks_template', 'en_US', []))->handle();

        $sendBody = $this->jsonBodyOf(0);

        $this->assertSame([
            'name'     => 'thanks_template',
            'language' => ['code' => 'en_US'],
        ], $sendBody['template']);
        $this->assertArrayNotHasKey('components', $sendBody['template']);
    }

    public function test_a_second_run_never_resends_an_accepted_template()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT1']]])),
        ]);

        (new SendTemplateMessage($thread->id, 'order_shipped', 'en_US', ['John', '12345']))->handle();

        $rowsBefore = KapsoMessage::where('thread_id', $thread->id)->count();

        // Empty queue: unlike SendReplyMessage, this job never makes a
        // trailing mark-read call, so an empty queue surviving handle()
        // without throwing is itself proof that no HTTP call at all was
        // attempted on the second run.
        $this->fakeResponses([]);

        (new SendTemplateMessage($thread->id, 'order_shipped', 'en_US', ['John', '12345']))->handle();

        $rowsAfter = KapsoMessage::where('thread_id', $thread->id)->count();
        $row       = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_TEMPLATE)->firstOrFail();

        $this->assertSame(0, count($this->history));
        $this->assertSame($rowsBefore, $rowsAfter);
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
        $this->assertSame('wamid.OUT1', $row->wamid);
    }

    public function test_a_final_failure_posts_one_red_line_item_and_fails_the_claim()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        // A marker from an earlier, unrelated accepted send on this same
        // thread (the sibling-failure semantics: template threads share the
        // THREAD_META_SENT_AT invariant with every other WhatsApp send) --
        // a final failure here must clear it, the same way
        // SendReplyMessage::finalizeFailure() does.
        $thread->setMeta(KapsoMessage::THREAD_META_SENT_AT, now()->toIso8601String());
        $thread->save();

        $this->fakeResponses([
            new Response(422, [], json_encode(['error' => 'Template not approved for this recipient'])),
        ]);

        $job = new SendTemplateMessage($thread->id, 'order_shipped', 'en_US', ['John', '12345']);
        // Deterministic "final attempt" seam -- see SendReplyTest's identical
        // use of this trick and its comment for why tries = 1 makes
        // attempts() >= tries true on a direct handle() call.
        $job->tries = 1;
        $job->handle();

        $row = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_TEMPLATE)->firstOrFail();
        $this->assertSame(KapsoMessage::SEND_STATE_FAILED, $row->send_state);
        $this->assertStringContainsString('Template not approved for this recipient', (string) $row->error);

        $lineItems = Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->get();
        $this->assertCount(1, $lineItems);
        $this->assertTrue((bool) $lineItems->first()->getMeta(KapsoMessage::LINEITEM_META_DELIVERY_FAILED));
        $this->assertStringContainsString('Template not approved for this recipient', $lineItems->first()->body);

        $thread = $thread->fresh();
        $this->assertNull($thread->getMeta(KapsoMessage::THREAD_META_SENT_AT), 'a recorded failure must clear an existing sent marker');
    }

    public function test_guards_bail_silently_on_an_undone_or_foreign_thread()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario(['state' => Thread::STATE_DRAFT]);

        // Empty queue: any HTTP attempt at all throws and fails this test.
        $this->fakeResponses([]);

        (new SendTemplateMessage($thread->id, 'order_shipped', 'en_US', ['John', '12345']))->handle();

        $this->assertSame(0, count($this->history));
        $this->assertSame(0, KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_TEMPLATE)->count());
    }
}

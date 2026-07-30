<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Attachment;
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

class SendReplyTest extends TestCase
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

    /**
     * A chat conversation on the WhatsApp channel, with a customer and a
     * default (unassigned) folder -- everything ProcessInboundMessage would
     * normally have set up, built by hand so the fixture controls exactly
     * what matters to this job (channel, mailbox, folder).
     */
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
        $inbound->contact_phone   = '491771234567';
        $inbound->status          = 'received';
        $inbound->save();

        return $inbound;
    }

    protected function makeReplyThread(Conversation $conversation, array $overrides = []): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id     = $conversation->id;
        $thread->user_id             = $agent->id;
        $thread->created_by_user_id  = $agent->id;
        $thread->type                = Thread::TYPE_MESSAGE;
        $thread->status               = Thread::STATUS_ACTIVE;
        $thread->state                = Thread::STATE_PUBLISHED;
        $thread->body                 = '<p>Hello &amp; welcome</p>';
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
     * Full working fixture: account, conversation, one inbound message
     * (opens the reply), and one published TYPE_MESSAGE reply thread.
     *
     * @return array{0: KapsoAccount, 1: Conversation, 2: KapsoMessage, 3: Thread}
     */
    protected function scenario(array $threadOverrides = []): array
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $inbound      = $this->seedInbound($account, $conversation);
        $thread       = $this->makeReplyThread($conversation, $threadOverrides);

        return [$account, $conversation, $inbound, $thread];
    }

    public function test_a_text_reply_is_sent_and_its_wamid_recorded()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT1']]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $sendRequest = $this->history[0]['request'];
        $sendBody    = $this->jsonBodyOf(0);

        $this->assertSame('Hello & welcome', $sendBody['text']['body']);
        $this->assertSame('491771234567', $sendBody['to']);
        $this->assertStringContainsString(
            'https://api.kapso.ai/meta/whatsapp/v24.0/'.$account->phone_number_id.'/messages',
            (string) $sendRequest->getUri()
        );

        $row = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_BODY)->firstOrFail();
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
        $this->assertSame('wamid.OUT1', $row->wamid);

        $markReadBody = $this->jsonBodyOf(1);
        $this->assertSame('wamid.IN1', $markReadBody['message_id']);
        $this->assertSame('read', $markReadBody['status']);
    }

    public function test_running_the_job_twice_sends_nothing_the_second_time()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT1']]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $rowsBefore = KapsoMessage::where('thread_id', $thread->id)->count();

        // An empty queue: any attempted HTTP call throws (MockHandler
        // exhausted), so this run only passes if it makes zero requests.
        $this->fakeResponses([]);

        (new SendReplyMessage($thread->id))->handle();

        $rowsAfter = KapsoMessage::where('thread_id', $thread->id)->count();
        $row       = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_BODY)->firstOrFail();

        $this->assertSame($rowsBefore, $rowsAfter);
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
        $this->assertSame('wamid.OUT1', $row->wamid);
    }

    public function test_an_attachment_goes_out_as_a_link_with_the_token()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $attachment = Attachment::create('photo.jpg', 'image/jpeg', null, 'fake-image-bytes', null, false, $thread->id, null);
        $thread->has_attachments = true;
        $thread->save();

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-TEXT']]])),
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-IMG']]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $imageBody = $this->jsonBodyOf(1);
        $this->assertSame('image', $imageBody['type']);
        $this->assertStringContainsString('?id=', $imageBody['image']['link']);
        $this->assertStringContainsString('&token=', $imageBody['image']['link']);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/'), $imageBody['image']['link']);

        $row = KapsoMessage::where('thread_id', $thread->id)
            ->where('part_key', KapsoMessage::partKeyForAttachment($attachment->id))
            ->firstOrFail();
        $this->assertSame($attachment->id, $row->attachment_id);
        $this->assertSame('wamid.OUT-IMG', $row->wamid);
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
    }

    public function test_a_non_image_attachment_is_sent_as_a_document_with_filename()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $attachment = Attachment::create('invoice.pdf', 'application/pdf', null, 'fake-pdf-bytes', null, false, $thread->id, null);
        $thread->has_attachments = true;
        $thread->save();

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-TEXT']]])),
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-DOC']]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $docBody = $this->jsonBodyOf(1);
        $this->assertSame('document', $docBody['type']);
        $this->assertSame('invoice.pdf', $docBody['document']['filename']);
        $this->assertStringContainsString('?id=', $docBody['document']['link']);

        $row = KapsoMessage::where('thread_id', $thread->id)
            ->where('part_key', KapsoMessage::partKeyForAttachment($attachment->id))
            ->firstOrFail();
        $this->assertSame('wamid.OUT-DOC', $row->wamid);
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
    }

    public function test_a_long_reply_is_chunked_not_doomed()
    {
        $longText = str_repeat('A', 4500);
        [$account, $conversation, $inbound, $thread] = $this->scenario(['body' => '<p>'.$longText.'</p>']);

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-1']]])),
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-2']]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $first  = $this->jsonBodyOf(0);
        $second = $this->jsonBodyOf(1);

        $this->assertSame(4000, mb_strlen($first['text']['body']));
        $this->assertSame(500, mb_strlen($second['text']['body']));

        $rowBody1 = KapsoMessage::where('thread_id', $thread->id)->where('part_key', 'body')->firstOrFail();
        $rowBody2 = KapsoMessage::where('thread_id', $thread->id)->where('part_key', 'body:2')->firstOrFail();

        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $rowBody1->send_state);
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $rowBody2->send_state);
        $this->assertSame('wamid.OUT-1', $rowBody1->wamid);
        $this->assertSame('wamid.OUT-2', $rowBody2->wamid);
    }

    public function test_an_undone_reply_is_never_sent()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario(['state' => Thread::STATE_DRAFT]);

        // Empty queue: any HTTP attempt throws.
        $this->fakeResponses([]);

        (new SendReplyMessage($thread->id))->handle();

        $this->assertSame(0, KapsoMessage::where('thread_id', $thread->id)->count());
    }

    public function test_request_failure_exhausts_retries_into_one_red_line_item()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $this->fakeResponses([
            new Response(500, [], json_encode(['error' => 'Kapso is down for maintenance'])),
        ]);

        $job = new SendReplyMessage($thread->id);
        // Deterministic "final attempt" seam: attempts() defaults to 1 when
        // no real queue Job is attached (see InteractsWithQueue::attempts()),
        // so tries = 1 makes `attempts() >= tries` true on the very first,
        // direct call to handle() -- exactly the condition that must hold on
        // a job's genuinely last retry.
        $job->tries = 1;
        $job->handle();

        $row = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_BODY)->firstOrFail();
        $this->assertSame(KapsoMessage::SEND_STATE_FAILED, $row->send_state);
        $this->assertStringContainsString('Kapso is down for maintenance', (string) $row->error);

        $lineItems = Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->get();
        $this->assertCount(1, $lineItems);
        $this->assertTrue((bool) $lineItems->first()->getMeta(KapsoMessage::LINEITEM_META_DELIVERY_FAILED));
        $this->assertStringContainsString('Kapso is down for maintenance', $lineItems->first()->body);

        // Rendering-level proof (the Stage 1 lesson: the row/body existing is
        // not the same as the agent seeing anything -- core only ever renders
        // a TYPE_LINEITEM thread via getActionText(), never $thread->body
        // directly; see thread.blade.php and ReconcileOutboundTest's own use
        // of this exact call). A full `conversations.view` page GET was tried
        // first here and had to be abandoned: it 500s for *any* conversation
        // in this environment, including a bare core-only fixture with no
        // KapsoWhatsApp involvement at all (confirmed with a throwaway
        // diagnostic test) -- `old('body', $conversation->body)` in
        // resources/views/conversations/reply.blade.php passes null into
        // e()/htmlspecialchars(), which PHP 8.1+ deprecates and this app's
        // error_reporting(-1) turns into a fatal ErrorException. That is a
        // pre-existing, unrelated core bug, not something introduced by this
        // job -- see the deviation note in task-4-report.md.
        $rendered = $lineItems->first()->getActionText('', true, false, null, 'Some Agent');
        $this->assertStringContainsString('Kapso is down for maintenance', $rendered);
    }

    public function test_mark_read_failure_does_not_fail_the_send()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT1']]])),
            new Response(500, [], json_encode(['error' => 'read receipt endpoint unhappy'])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $row = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_BODY)->firstOrFail();
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
        $this->assertSame('wamid.OUT1', $row->wamid);

        $this->assertSame(0, Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->count());
    }

    public function test_a_partial_retry_resends_only_the_unaccepted_part()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $attachment = Attachment::create('photo.jpg', 'image/jpeg', null, 'fake-image-bytes', null, false, $thread->id, null);
        $thread->has_attachments = true;
        $thread->save();

        // Run 1 (final attempt): text OK, image 500 -> body accepted, att failed + one line item.
        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-TEXT']]])),
            new Response(500, [], json_encode(['error' => 'temporary image relay error'])),
        ]);

        $job1 = new SendReplyMessage($thread->id);
        $job1->tries = 1;
        $job1->handle();

        $bodyRow = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_BODY)->firstOrFail();
        $attRow  = KapsoMessage::where('thread_id', $thread->id)
            ->where('part_key', KapsoMessage::partKeyForAttachment($attachment->id))->firstOrFail();

        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $bodyRow->send_state);
        $this->assertSame('wamid.OUT-TEXT', $bodyRow->wamid);
        $this->assertSame(KapsoMessage::SEND_STATE_FAILED, $attRow->send_state);
        $this->assertSame(1, Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->count());

        // Run 2 (a fresh job): only the still-unaccepted attachment part may
        // hit the network -- an already-accepted body part making a request
        // here would exhaust this 2-item queue before mark-read and fail
        // the test.
        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-IMG']]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $bodyRowAfter = $bodyRow->fresh();
        $attRowAfter  = $attRow->fresh();

        $this->assertSame('wamid.OUT-TEXT', $bodyRowAfter->wamid, 'the already-accepted body part must be untouched');
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $bodyRowAfter->send_state);

        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $attRowAfter->send_state);
        $this->assertSame('wamid.OUT-IMG', $attRowAfter->wamid);
    }

    /**
     * Not one of the sketches in the plan, but required by the task brief:
     * a non-final attempt must rethrow so Laravel's queue retries the job,
     * leaving the claimed-but-unsent part row alone (still `sending`) and
     * creating no line item -- only the last attempt gets to give up.
     */
    public function test_a_non_final_attempt_rethrows_instead_of_giving_up()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $this->fakeResponses([
            new Response(500, [], json_encode(['error' => 'temporary hiccup'])),
        ]);

        $job = new SendReplyMessage($thread->id);
        // Default $tries = 3; attempts() defaults to 1 with no queue Job
        // attached, so 1 < 3 -- this is not the final attempt.
        $this->expectException(\Modules\KapsoWhatsApp\Exceptions\KapsoApiException::class);

        try {
            $job->handle();
        } finally {
            $row = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_BODY)->first();
            $this->assertNotNull($row);
            $this->assertSame(KapsoMessage::SEND_STATE_SENDING, $row->send_state,
                'a non-final attempt must leave the claimed part alone for the retry to pick up');
            $this->assertSame(0, Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->count(),
                'no line item until the retries are actually exhausted');
        }
    }
}

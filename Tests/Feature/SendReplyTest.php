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
        // "+"-prefixed, matching what ProcessInboundMessage actually writes
        // (PhoneNumber::toE164()) -- a bare-digit fixture here would hide a
        // regression where the job forgets to strip the "+" for Meta's `to`
        // field, since the strip would then be a silent no-op.
        $inbound->contact_phone   = '+491771234567';
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

        // Full payload, not just the fields that happen to matter today --
        // a stray/missing key elsewhere in the shape (messaging_product,
        // type) would otherwise slip past unnoticed.
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'to'                => '491771234567',
            'type'              => 'text',
            'text'              => ['body' => 'Hello & welcome'],
        ], $sendBody);
        $this->assertStringContainsString(
            'https://api.kapso.ai/meta/whatsapp/v24.0/'.$account->phone_number_id.'/messages',
            (string) $sendRequest->getUri()
        );

        $row = KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_BODY)->firstOrFail();
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
        $this->assertSame('wamid.OUT1', $row->wamid);
        // The stored column must stay "+"-prefixed E.164, verbatim from the
        // inbound row -- not the bare digits sent to Meta -- or
        // ReconcileOutboundMessage::resolveConversationId()'s exact match
        // against this same column silently stops finding this conversation.
        $this->assertSame('+491771234567', $row->contact_phone);

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

        // An empty queue does not, on its own, prove nothing was sent: the
        // job always re-fires markMessageRead() after the loop regardless of
        // whether any part actually needed sending (it is idempotent and
        // best-effort), and that alone would exhaust this empty queue and
        // throw. But markReadBestEffort() catches that throw internally and
        // never lets it surface, so it proves nothing either way -- only a
        // *send* attempt (claimAndSend() has no such catch) would actually
        // propagate out of handle() and fail this test. The real proof that
        // nothing was resent is the row-state assertions below: an
        // already-`accepted` part must keep its row count and wamid exactly
        // as run 1 left them.
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
        // resources/views/conversations/view.blade.php:332 passes null into
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

    /**
     * The chunk test above (test_a_long_reply_is_chunked_not_doomed) uses
     * plain ASCII, where a byte-based str_split() and the actual
     * mb_str_split() implementation happen to produce identical results --
     * every ASCII character is exactly one byte, so that test alone would
     * stay green even if mb_str_split() regressed to str_split(). 'ä' is two
     * UTF-8 bytes, so a byte-based split landing mid-character would show up
     * here as a chunk whose mb_strlen() isn't 4000 and/or that isn't valid
     * UTF-8, where the character-based mb_str_split() this job actually uses
     * stays exactly 4000 characters and valid UTF-8.
     */
    public function test_a_long_multibyte_reply_is_chunked_on_character_boundaries()
    {
        $longText = str_repeat('ä', 4500);
        [$account, $conversation, $inbound, $thread] = $this->scenario(['body' => '<p>'.$longText.'</p>']);

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-1']]])),
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-2']]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $chunk = $this->jsonBodyOf(0)['text']['body'];

        $this->assertSame(4000, mb_strlen($chunk));
        $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
    }

    /**
     * Helper::htmlToText() passes its argument straight into
     * str_ireplace()/Html2Text, which reject null; an attachment-only reply
     * leaves $thread->body NULL (not ''), and this app escalates that
     * deprecation to a fatal ErrorException (verified live) -- not a
     * KapsoApiException, so it would previously burn every retry with the
     * attachment never sent and no line item either. Guards the
     * `(string) $thread->body` cast in parts().
     */
    public function test_a_null_body_thread_with_an_attachment_still_sends_the_attachment()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario(['body' => null]);

        $attachment = Attachment::create('photo.jpg', 'image/jpeg', null, 'fake-image-bytes', null, false, $thread->id, null);
        $thread->has_attachments = true;
        $thread->save();

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.OUT-IMG']]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();

        $this->assertSame(0, KapsoMessage::where('thread_id', $thread->id)->where('part_key', KapsoMessage::PART_BODY)->count());

        $row = KapsoMessage::where('thread_id', $thread->id)
            ->where('part_key', KapsoMessage::partKeyForAttachment($attachment->id))
            ->firstOrFail();
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);
        $this->assertSame('wamid.OUT-IMG', $row->wamid);

        $imageBody = $this->jsonBodyOf(0);
        $this->assertSame('image', $imageBody['type']);
    }

    /**
     * failed() is the safety net Laravel's queue worker calls automatically
     * once retries are exhausted for any exception handle()'s own catch
     * block does not itself recognise (only KapsoApiException is caught
     * there) -- see the class docblock and failed()'s own. Exercised
     * directly here, the same way the real queue worker would invoke it,
     * against a row left `sending` as if a previous attempt had crashed
     * mid-send, rather than trying to coax a genuine non-KapsoApiException
     * throw out of the HTTP mock.
     */
    public function test_failed_hook_marks_sending_parts_failed_and_posts_one_line_item()
    {
        [$account, $conversation, $inbound, $thread] = $this->scenario();

        $row = new KapsoMessage();
        $row->account_id      = $account->id;
        $row->conversation_id = $conversation->id;
        $row->thread_id       = $thread->id;
        $row->part_key        = KapsoMessage::PART_BODY;
        $row->direction       = KapsoMessage::DIRECTION_OUTBOUND;
        $row->send_state      = KapsoMessage::SEND_STATE_SENDING;
        $row->contact_phone   = $inbound->contact_phone;
        $row->save();

        (new SendReplyMessage($thread->id))->failed(new \RuntimeException('worker process was killed'));

        $row = $row->fresh();
        $this->assertSame(KapsoMessage::SEND_STATE_FAILED, $row->send_state);
        $this->assertStringContainsString('worker process was killed', (string) $row->error);

        $lineItems = Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->get();
        $this->assertCount(1, $lineItems);
        $this->assertStringContainsString('worker process was killed', $lineItems->first()->body);
    }

    /**
     * Note on registration-arity safety (there is no dedicated test for
     * this -- it cannot be exercised through Eventy::action() the way the
     * provider registers it, so this is documentation, not a check):
     * SendReplyToWhatsApp::handle($conversation, $replies, $customer) has
     * three REQUIRED, default-less parameters. If the provider registration
     * were ever accidentally reverted to pass only 1 arg, PHP raises an
     * ArgumentCountError while binding the call -- BEFORE the method body
     * (and so before this listener's own try/catch) ever runs -- and that
     * error escapes straight into core's TriggerAction wrapper around
     * \Helper::backgroundAction(). It is the missing-argument arity itself,
     * not any foreach or dispatch-count reasoning inside handle(), that
     * would surface such a mistake, and only for as long as all three
     * parameters stay required: giving any of them a default would silently
     * mask a wrong-arity registration instead of raising.
     */

    /**
     * The listener side of Task 5: core's `chat_conversation.send_reply`
     * action (fired for every chat-type conversation instead of emailing,
     * see app/Listeners/SendReplyToCustomer.php) hands this hook the
     * conversation's WHOLE published thread history, newest-first --
     * SendReplyToCustomer.php:41 builds $replies from
     * Conversation::getThreads() over every published TYPE_CUSTOMER /
     * TYPE_MESSAGE / TYPE_LINEITEM thread, and the trim loop at :48-57 only
     * removes threads NEWER than the triggering one, so first() is always
     * the triggering reply and everything behind it is older, already-
     * delivered history (core's own email job treats this identical
     * collection the same way: SendReplyToCustomer.php:129 takes
     * ->threads->first() as the message to send). This fixture is built the
     * way core actually builds it -- triggering reply first, then an older
     * customer message, then an OLDER published agent reply behind it (the
     * replay hazard: iterating the whole collection would re-send that old
     * agent reply to the customer on every new reply). Exactly one dispatch,
     * for $newReply and nothing else, is what proves the listener never
     * iterates.
     */
    public function test_only_the_triggering_reply_is_dispatched_never_the_history()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $customer     = $conversation->customer;

        // Built with a real (non-faked) Bus: a TYPE_CUSTOMER thread makes
        // Conversation::refreshConversations() dispatch a queued broadcast
        // event (ThreadObserver::created() -> Conversation.php:2272-2274),
        // which errors under Bus::fake() in this app
        // (BusFake::getCommandHandler() does not exist) -- unrelated to
        // anything this test is about, so the fixtures are built before the
        // fake, and only the dispatch under test runs with it active.
        $olderAgentReply     = $this->makeReplyThread($conversation);
        $olderCustomerThread = $this->makeReplyThread($conversation, ['type' => Thread::TYPE_CUSTOMER]);
        $newReply            = $this->makeReplyThread($conversation);

        \Bus::fake();

        \Eventy::action(
            'chat_conversation.send_reply',
            $conversation,
            collect([$newReply, $olderCustomerThread, $olderAgentReply]),
            $customer
        );

        // assertDispatchedTimes() itself is `protected` in this app's
        // Laravel 5.5-era BusFake and cannot be called through the Facade;
        // assertDispatched()'s numeric-$callback branch is the public path
        // to the same assertion (see BusFake::assertDispatched()).
        \Bus::assertDispatched(SendReplyMessage::class, 1);
        \Bus::assertDispatched(SendReplyMessage::class, function ($job) use ($newReply) {
            return $job->threadId === $newReply->id;
        });
    }

    /**
     * The hook fires for every chat conversation, not only WhatsApp ones --
     * core has no way to know which channel module owns a given chat
     * conversation. This module's listener is the only thing narrowing that
     * down, so a conversation on some other channel (module or otherwise)
     * must be a silent no-op.
     */
    public function test_the_hook_ignores_conversations_of_other_channels()
    {
        \Bus::fake();

        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $conversation->channel = 1;
        $conversation->save();
        $reply    = $this->makeReplyThread($conversation);
        $customer = $conversation->customer;

        \Eventy::action('chat_conversation.send_reply', $conversation, collect([$reply]), $customer);

        \Bus::assertNotDispatched(SendReplyMessage::class);
    }

    /**
     * The hook fires after Conversation::UNDO_TIMOUT with a $replies
     * collection snapshotted at "send" time -- by the time it actually runs,
     * the agent may have clicked undo, reverting the real DB row back to
     * STATE_DRAFT. The listener must re-fetch by id and skip rather than
     * trust the snapshot.
     *
     * The stale snapshot here is a real in-memory Thread model that still
     * holds TYPE_MESSAGE + STATE_PUBLISHED in its own attributes (exactly
     * what the object looked like at "send" time) while the underlying DB
     * row is reverted out from under it via a direct query update, which
     * does not touch this already-loaded instance. A bare
     * (object) ['id' => $reply->id] would also pass against an
     * implementation that -- wrongly -- trusted the snapshot's own type/
     * state fields instead of re-fetching (both would read null and get
     * skipped for the wrong reason), so it could not actually discriminate
     * a snapshot-trusting implementation from a re-fetching one. This
     * fixture can: it fails against a snapshot-trusting implementation
     * (which would see TYPE_MESSAGE + STATE_PUBLISHED and dispatch) and
     * only passes when the listener re-fetches by id.
     */
    public function test_the_hook_skips_replies_that_were_undone()
    {
        \Bus::fake();

        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $reply        = $this->makeReplyThread($conversation);
        $customer     = $conversation->customer;

        $staleSnapshot = Thread::find($reply->id);

        Thread::where('id', $reply->id)->update(['state' => Thread::STATE_DRAFT]);

        \Eventy::action('chat_conversation.send_reply', $conversation, collect([$staleSnapshot]), $customer);

        \Bus::assertNotDispatched(SendReplyMessage::class);
    }

    /**
     * $replies is a raw argument on an Eventy action, not type-hinted --
     * nothing stops a broken caller (or a future core refactor) from
     * passing something that is not a Collection at all. The listener's
     * try/catch must swallow that (an int has no ->first()) rather than let
     * the error escape into core's TriggerAction wrapper around
     * \Helper::backgroundAction(), which would break the whole queued job.
     */
    public function test_a_garbage_replies_argument_is_swallowed_and_logged()
    {
        \Bus::fake();

        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $customer     = $conversation->customer;

        \Eventy::action('chat_conversation.send_reply', $conversation, 5, $customer);

        \Bus::assertNotDispatched(SendReplyMessage::class);
    }
}

<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Thread;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Jobs\ReconcileOutboundMessage;
use Modules\KapsoWhatsApp\Jobs\SendReplyMessage;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * The end-to-end loop this module exists to close: SendReplyMessage (Task 4)
 * hands ReconcileOutboundMessage (Task 1) a wamid it accepted, and the two
 * must recognise each other -- a `sent` webhook for our own accepted send
 * must mark the reply "Sent via WhatsApp" rather than be mistaken for a
 * foreign send and given its own duplicate thread (ReconcileOutboundTest
 * already pins the foreign-send path; this file pins the "it's actually
 * ours" path plus the "failed always wins" hardening for it specifically).
 *
 * Fixture idioms (account/conversation/thread builders, KapsoClient::fakeHttp
 * with a Guzzle MockHandler) are copied from SendReplyTest rather than shared
 * via inheritance, matching this module's existing convention of each test
 * class owning its own fixture helpers (SendReplyTest and ReconcileOutboundTest
 * already duplicate makeAccount()/seedInbound() rather than share a base).
 */
class SentMarkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The API key is a module-wide setting, not a per-account attribute.
        Settings::setApiKey('key');
    }

    protected function fakeResponses(array $queue): void
    {
        KapsoClient::fakeHttp(new Client(['handler' => HandlerStack::create(new MockHandler($queue))]));
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
     * default (unassigned) folder -- see SendReplyTest::makeConversation()
     * for the full rationale.
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
        $inbound->contact_phone   = '+491771234567';
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
        $thread->status              = Thread::STATUS_ACTIVE;
        $thread->state                = Thread::STATE_PUBLISHED;
        $thread->body                 = '<p>Hello &amp; welcome</p>';
        $thread->source_via            = Thread::PERSON_USER;
        $thread->source_type           = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id           = $conversation->customer_id;
        $thread->save();

        return $thread;
    }

    /**
     * Full working fixture: account, conversation, one inbound message (opens
     * the reply), and one published TYPE_MESSAGE reply thread -- mirrors
     * SendReplyTest::scenario().
     *
     * @return array{0: KapsoAccount, 1: Conversation, 2: KapsoMessage, 3: Thread}
     */
    protected function scenario(): array
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $inbound      = $this->seedInbound($account, $conversation);
        $thread       = $this->makeReplyThread($conversation);

        return [$account, $conversation, $inbound, $thread];
    }

    /**
     * Shape of a `whatsapp.message.sent` webhook for the given wamid --
     * mirrors ReconcileOutboundTest::sentPayload(). Note: none of this file's
     * tests actually depend on `to`/`conversation` resolving to a
     * conversation via ReconcileOutboundMessage::resolveOutboundConversation()
     * -- every wamid here is already known (handle()'s `$known` lookup finds
     * it before that method would ever run), so these fields exist only to
     * keep the payload realistic.
     */
    protected function sentPayload(string $wamid): array
    {
        return [
            'message' => [
                'id' => $wamid, 'type' => 'text', 'to' => '491771234567',
                'text' => ['body' => 'irrelevant for a known wamid'],
                'kapso' => ['direction' => 'outbound', 'status' => 'sent', 'content' => 'irrelevant for a known wamid'],
            ],
            'conversation' => [
                'id' => 'conv_marker', 'phone_number_id' => '123456789012345',
                'kapso' => ['contact_name' => 'Wanda WhatsApp'],
            ],
            'phone_number_id' => '123456789012345',
        ];
    }

    /**
     * Shape of a `whatsapp.message.failed` webhook for the given wamid --
     * mirrors ReconcileOutboundTest::failedPayload().
     */
    protected function failedPayload(string $wamid): array
    {
        $payload = $this->sentPayload($wamid);

        $payload['message']['kapso']['status']   = 'failed';
        $payload['message']['kapso']['statuses'] = [[
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message',
                          'message' => 'Message failed to send because more than 24 hours have passed']],
        ]];

        return $payload;
    }

    /**
     * Runs SendReplyMessage against a fresh scenario with a fixed wamid, the
     * same accepted-send + best-effort-mark-read shape every test in this
     * file needs before it can feed the resulting wamid back into
     * ReconcileOutboundMessage.
     */
    protected function sendAccepted(array $scenario, string $wamid): void
    {
        [, , , $thread] = $scenario;

        $this->fakeResponses([
            new Response(200, [], json_encode(['messages' => [['id' => $wamid]]])),
            new Response(200, [], json_encode(['success' => true])),
        ]);

        (new SendReplyMessage($thread->id))->handle();
    }

    /**
     * Fires the `thread.meta` action exactly the way
     * resources/views/conversations/partials/thread.blade.php:295 does
     * (@action('thread.meta', $thread, $loop, $threads, $conversation,
     * $mailbox)) and captures the echoed output -- a page-level GET of
     * conversations.view is not usable here: it 500s for a fresh-session test
     * request regardless of this module's own involvement (a pre-existing
     * core bug in view.blade.php:332, e(old($conversation->body)) fed a null
     * under this app's escalated deprecations; see SendReplyTest's own
     * rendering-proof comment for the same finding). This is the module's
     * own unit-level proof, the same spirit as ReconcileOutboundTest's use of
     * getActionText() for the failure-marker rendering path.
     */
    protected function renderThreadMeta(Thread $thread, Conversation $conversation): string
    {
        ob_start();
        \Eventy::action('thread.meta', $thread, null, collect([$thread]), $conversation, $conversation->mailbox);

        return ob_get_clean();
    }

    public function test_our_own_send_gets_a_marker_instead_of_a_foreign_thread()
    {
        $scenario = $this->scenario();
        [$account, $conversation, , $thread] = $scenario;

        $this->sendAccepted($scenario, 'wamid.OUT7');

        $row = KapsoMessage::where('wamid', 'wamid.OUT7')->firstOrFail();
        $this->assertSame($thread->id, $row->thread_id);
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);

        $threadsBefore = Thread::count();

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $this->sentPayload('wamid.OUT7')))->handle();

        // Pins the "recognised, not duplicated" promise from Stage 1: a
        // `sent` event for a wamid we already accepted must never be mistaken
        // for a foreign send and given its own outside-FreeScout thread.
        $this->assertSame($threadsBefore, Thread::count(),
            'a sent event for our own accepted send must not create a new thread');

        $row = $row->fresh();
        $this->assertSame('sent', $row->status);
        $this->assertSame(KapsoMessage::SEND_STATE_ACCEPTED, $row->send_state);

        $html = $this->renderThreadMeta($thread->fresh(), $conversation);
        $this->assertStringContainsString('Sent via WhatsApp', $html);
    }

    public function test_a_failed_send_never_shows_the_sent_marker()
    {
        $scenario = $this->scenario();
        [$account, $conversation, , $thread] = $scenario;

        $this->sendAccepted($scenario, 'wamid.OUT7');

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $this->failedPayload('wamid.OUT7')))->handle();

        $row = KapsoMessage::where('wamid', 'wamid.OUT7')->firstOrFail();
        $this->assertSame('failed', $row->status);

        $thread = $thread->fresh();
        $this->assertNull($thread->getMeta(KapsoMessage::THREAD_META_SENT_AT),
            'a failed send must never carry the sent-marker meta');

        $html = $this->renderThreadMeta($thread, $conversation);
        $this->assertSame('', $html, 'a failed send must never render the sent marker');

        // The existing failure-line-item behaviour (DeliveryFailureLineItem)
        // must still fire and still render its text via getActionText(),
        // exactly as ReconcileOutboundTest already pins for the general case.
        $lineItem = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)->orderBy('id', 'desc')->first();
        $this->assertNotNull($lineItem, 'a delivery failure must still be visible on the conversation');

        $rendered = $lineItem->getActionText('', true, false, null, 'Some Agent');
        $this->assertStringContainsString('131047', $rendered);
    }

    public function test_a_sent_webhook_after_failure_does_not_resurrect_the_marker()
    {
        $scenario = $this->scenario();
        [$account, $conversation, , $thread] = $scenario;

        $this->sendAccepted($scenario, 'wamid.OUT7');

        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.failed', $this->failedPayload('wamid.OUT7')))->handle();

        // The sibling `sent` event finally arrives, late -- the plan's
        // "failed-first hardening must keep winning".
        (new ReconcileOutboundMessage($account->id, 'whatsapp.message.sent', $this->sentPayload('wamid.OUT7')))->handle();

        $row = KapsoMessage::where('wamid', 'wamid.OUT7')->firstOrFail();
        $this->assertSame('failed', $row->status, 'a late sent event must not resurrect a failed row');

        $thread = $thread->fresh();
        $this->assertNull($thread->getMeta(KapsoMessage::THREAD_META_SENT_AT),
            'a late sent event for an already-failed row must never add the sent-marker meta');

        $html = $this->renderThreadMeta($thread, $conversation);
        $this->assertSame('', $html, 'a late sent event for an already-failed row must never render the sent marker');
    }
}

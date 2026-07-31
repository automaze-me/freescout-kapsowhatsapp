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
use Modules\KapsoWhatsApp\Jobs\SendTemplateMessage;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * Task 3 of Stage 3c: the two agent-facing JSON endpoints (list + send) the
 * closed-window picker talks to. Authorisation here is deliberately NOT
 * KapsoWhatsAppController::authorizeAdmin() -- these routes must be usable by
 * any agent who can reply to the conversation, the same population core's
 * own `send_reply` action admits
 * (app/Http/Controllers/ConversationsController.php:706,
 * `$user->can('view', $conversation)`), which is why several tests here
 * exercise a non-admin agent explicitly rather than only ever using
 * adminUser() the way the rest of this module's HTTP tests do.
 *
 * Fixture idiom (account/conversation/inbound row) copied from
 * SendTemplateTest, per this module's convention of each test file owning
 * its own fixtures.
 */
class TemplateEndpointsTest extends TestCase
{
    protected $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The API key is a module-wide setting, not a per-account attribute.
        Settings::setApiKey('key');
    }

    /**
     * This app's TestResponse::assertJson()/json() go through PHPUnit's
     * assertArraySubset(), removed in the PHPUnit 9 this suite runs on --
     * see the other Feature tests in this module (NumberPickerTest,
     * WebhookAdminActionsTest), which all read JSON bodies via
     * getContent()+json_decode() for the same reason rather than Laravel's
     * higher-level JSON test helpers.
     */
    protected function decodeJson($response): array
    {
        return (array) json_decode($response->getContent(), true);
    }

    protected function fakeResponses(array $queue): void
    {
        $this->history = [];
        $stack         = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        KapsoClient::fakeHttp(new Client(['handler' => $stack]));
    }

    /**
     * A single-page Meta-shaped {"data": [...]} response. $templates
     * defaults to one APPROVED, two-placeholder text-body template so most
     * tests need not restate the shape.
     */
    protected function templatesResponse(array $templates = null): Response
    {
        if ($templates === null) {
            $templates = [[
                'name'       => 'order_shipped',
                'language'   => 'en_US',
                'status'     => 'APPROVED',
                'category'   => 'UTILITY',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Hello {{1}}, order {{2}} shipped'],
                ],
            ]];
        }

        return new Response(200, [], json_encode(['data' => $templates]));
    }

    protected function makeAccount(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill([
            'name'                => 'Support',
            'phone_number_id'     => '123456789012345',
            'business_account_id' => '999888777666555',
            'mailbox_id'          => $this->testMailbox()->id,
            'is_active'           => true,
        ]);
        $account->webhook_secret = 'secret';
        $account->save();

        return $account;
    }

    protected function makeConversation(KapsoAccount $account, int $channel = KapsoAccount::CHANNEL): Conversation
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Wanda', 'last_name' => 'WhatsApp']);

        $folder = Folder::where('mailbox_id', $account->mailbox_id)
            ->where('type', Folder::TYPE_UNASSIGNED)
            ->first();

        $conversation = new Conversation();
        $conversation->type        = Conversation::TYPE_CHAT;
        $conversation->channel     = $channel;
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
        // "+"-prefixed, matching what ProcessInboundMessage actually writes.
        $inbound->contact_phone   = '+491771234567';
        $inbound->status          = 'received';
        $inbound->save();

        return $inbound;
    }

    public function test_the_list_endpoint_returns_eligible_templates_as_json()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedInbound($account, $conversation);

        $this->fakeResponses([$this->templatesResponse()]);

        $response = $this->actingAs($this->adminUser())
            ->getJson(route('kapsowhatsapp.templates.list', $conversation->id));

        $response->assertStatus(200);
        $json = $this->decodeJson($response);
        $this->assertSame([
            ['name' => 'order_shipped', 'language' => 'en_US', 'body' => 'Hello {{1}}, order {{2}} shipped', 'variables' => 2],
        ], $json['templates']);

        $this->assertStringContainsString(
            'https://api.kapso.ai/meta/whatsapp/v24.0/'.$account->business_account_id.'/message_templates',
            (string) $this->history[0]['request']->getUri()
        );
    }

    public function test_the_list_endpoint_reports_kapso_failure_honestly()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedInbound($account, $conversation);

        $this->fakeResponses([new Response(500, [], json_encode(['error' => 'boom']))]);

        $response = $this->actingAs($this->adminUser())
            ->getJson(route('kapsowhatsapp.templates.list', $conversation->id));

        // 200, not 500: the picker reads {error: ...} rather than a stack
        // trace or an HTTP failure of the endpoint itself.
        $response->assertStatus(200);
        $json = $this->decodeJson($response);
        $this->assertArrayHasKey('error', $json);
        $this->assertArrayNotHasKey('templates', $json);
        $this->assertStringNotContainsString('Exception', $json['error']);
        $this->assertStringNotContainsString('.php', $json['error']);
    }

    public function test_both_endpoints_require_an_authenticated_user()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedInbound($account, $conversation);

        $this->get(route('kapsowhatsapp.templates.list', $conversation->id))->assertStatus(302);
        $this->post(route('kapsowhatsapp.templates.send', $conversation->id), [])->assertStatus(302);
    }

    public function test_a_non_whatsapp_conversation_is_rejected()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account, 1);

        $this->actingAs($this->adminUser())
            ->getJson(route('kapsowhatsapp.templates.list', $conversation->id))
            ->assertStatus(404);

        $this->actingAs($this->adminUser())
            ->postJson(route('kapsowhatsapp.templates.send', $conversation->id), [])
            ->assertStatus(404);
    }

    /**
     * The binding requirement (plan Task 3 / Global Constraints): this is
     * NOT authorizeAdmin() -- a non-admin agent with no access to the
     * conversation's mailbox must still be denied.
     */
    public function test_a_non_admin_agent_without_mailbox_access_is_denied()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedInbound($account, $conversation);

        $agent = $this->regularUser();
        // Deliberately not attached to $account->mailbox_id.

        $this->actingAs($agent)
            ->getJson(route('kapsowhatsapp.templates.list', $conversation->id))
            ->assertStatus(403);
    }

    /**
     * The other half of the same requirement: a non-admin agent who CAN
     * reply to this conversation (mailbox member) must be able to use both
     * endpoints -- proving this is not silently admin-only.
     */
    public function test_a_non_admin_agent_with_mailbox_access_can_list_templates()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedInbound($account, $conversation);

        $agent = $this->regularUser();
        $agent->mailboxes()->attach($account->mailbox_id);

        $this->fakeResponses([$this->templatesResponse()]);

        $response = $this->actingAs($agent)
            ->getJson(route('kapsowhatsapp.templates.list', $conversation->id));

        $response->assertStatus(200);
        $this->assertCount(1, $this->decodeJson($response)['templates']);
    }

    public function test_send_creates_the_thread_and_dispatches_the_job()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedInbound($account, $conversation);
        $agent = $this->adminUser();

        $this->fakeResponses([$this->templatesResponse([[
            'name'       => 'order_shipped',
            'language'   => 'en_US',
            'status'     => 'APPROVED',
            'category'   => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}, thanks for shopping with us'],
            ],
        ]])]);

        // Fixtures above are built with a real (non-faked) Bus; only the
        // request under test runs with Bus::fake() active (see
        // SendReplyTest::test_only_the_triggering_reply_is_dispatched_never_the_history
        // for why this ordering matters in this app's Laravel 5.5 BusFake).
        \Bus::fake();

        $response = $this->actingAs($agent)->postJson(route('kapsowhatsapp.templates.send', $conversation->id), [
            'name'      => 'order_shipped',
            'language'  => 'en_US',
            // Deliberately contains '&' -- proves the thread body is
            // escaped, not just substituted.
            'variables' => ['Ann & Sons'],
        ]);

        $response->assertStatus(200);
        $threadId = $this->decodeJson($response)['thread_id'] ?? null;
        $this->assertNotNull($threadId);

        $thread = Thread::findOrFail($threadId);
        $this->assertSame(Thread::TYPE_MESSAGE, (int) $thread->type);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $thread->state);
        $this->assertSame(Thread::PERSON_USER, (int) $thread->source_via);
        $this->assertSame($agent->id, $thread->created_by_user_id);
        $this->assertSame(nl2br(e('Hi Ann & Sons, thanks for shopping with us')), $thread->body);

        $conversation = $conversation->fresh();
        $this->assertSame(Conversation::PERSON_USER, (int) $conversation->last_reply_from);

        \Bus::assertDispatched(SendTemplateMessage::class, 1);
        \Bus::assertDispatched(SendTemplateMessage::class, function ($job) use ($threadId) {
            return $job->threadId === $threadId
                && $job->templateName === 'order_shipped'
                && $job->languageCode === 'en_US'
                && $job->variables === ['Ann & Sons'];
        });
        // The direct-creation invariant carried over from Stage 3a/3c's
        // design: this path must never also trigger the ordinary chat-reply
        // send, which would double-send the message.
        \Bus::assertNotDispatched(SendReplyMessage::class);
    }

    public function test_send_validates_variables()
    {
        $account      = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $this->seedInbound($account, $conversation);
        $agent = $this->adminUser();

        $this->fakeResponses([$this->templatesResponse([[
            'name'       => 'order_shipped',
            'language'   => 'en_US',
            'status'     => 'APPROVED',
            'category'   => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}, order {{2}} shipped'],
            ],
        ]])]);

        \Bus::fake();

        $response = $this->actingAs($agent)->postJson(route('kapsowhatsapp.templates.send', $conversation->id), [
            'name'      => 'order_shipped',
            'language'  => 'en_US',
            // Second value blank -- validation must re-fetch the template
            // list via the faked client and compare counts/blankness.
            'variables' => ['Ann', ''],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('error', $this->decodeJson($response));
        $this->assertSame(0, Thread::where('conversation_id', $conversation->id)->count());
        \Bus::assertNotDispatched(SendTemplateMessage::class);
    }
}

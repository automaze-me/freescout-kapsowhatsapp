<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\WebhookRegistrar;
use Modules\KapsoWhatsApp\Tests\TestCase;

class WebhookRegistrationTest extends TestCase
{
    protected $history = [];

    protected function makeAccount(array $overrides = []): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill(array_merge([
            'name'            => 'Support',
            'phone_number_id' => '1'.uniqid(),
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ], $overrides));
        $account->api_key = 'key-abc';
        $account->save();

        return $account;
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

    public function test_registering_creates_a_webhook_and_stores_its_id_and_generated_secret()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => []])),                        // list: none of ours
            new Response(201, [], json_encode(['data' => ['id' => 'wh-new', 'active' => true, 'url' => $url]])),
        ]);

        $account = $this->makeAccount();
        (new WebhookRegistrar($account))->register();

        $account = $account->fresh();
        $this->assertSame('wh-new', $account->webhook_id);
        $this->assertSame($url, $account->webhook_url);
        $this->assertTrue($account->webhook_active);
        $this->assertNull($account->webhook_error);
        $this->assertNotNull($account->webhook_checked_at);

        $this->assertNotEmpty($account->webhook_secret);
        $this->assertGreaterThanOrEqual(32, strlen($account->webhook_secret));

        $sent = $this->jsonBodyOf(1)['whatsapp_webhook'];
        $this->assertSame($url, $sent['url']);
        $this->assertSame('kapso', $sent['kind']);
        $this->assertSame('v2', $sent['payload_version']);
        $this->assertTrue($sent['active']);
        $this->assertFalse($sent['buffer_enabled'], 'buffering must be switched off explicitly, not left to a Kapso default');
        $this->assertSame([
            'whatsapp.message.received',
            'whatsapp.message.sent',
            'whatsapp.message.failed',
        ], $sent['events']);
        $this->assertSame($account->webhook_secret, $sent['secret_key'], 'Kapso must be given the same secret we stored');
    }

    /**
     * The trap this whole feature has to avoid: during the parallel run the
     * n8n bridge has its own webhook on the same number. Registering must
     * create ours and leave theirs completely untouched.
     */
    public function test_a_webhook_belonging_to_something_else_is_never_updated()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                ['id' => 'wh-n8n', 'url' => 'https://n8n.example.com/webhook/kapso', 'active' => true],
            ]])),
            new Response(201, [], json_encode(['data' => ['id' => 'wh-ours', 'active' => true, 'url' => $url]])),
        ]);

        $account = $this->makeAccount();
        (new WebhookRegistrar($account))->register();

        $this->assertSame('wh-ours', $account->fresh()->webhook_id);
        $this->assertCount(2, $this->history, 'exactly one list and one create');
        $this->assertSame('POST', $this->history[1]['request']->getMethod());

        foreach ($this->history as $entry) {
            $this->assertStringNotContainsString(
                'wh-n8n',
                (string) $entry['request']->getUri(),
                'the bridge webhook must never be addressed'
            );
        }
    }

    public function test_an_existing_webhook_on_our_own_url_is_adopted_rather_than_duplicated()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                ['id' => 'wh-mine', 'url' => $url, 'active' => false],
            ]])),
            new Response(200, [], json_encode(['data' => ['id' => 'wh-mine', 'url' => $url, 'active' => true]])),
        ]);

        $account = $this->makeAccount();
        (new WebhookRegistrar($account))->register();

        $this->assertSame('wh-mine', $account->fresh()->webhook_id);
        $this->assertSame('PATCH', $this->history[1]['request']->getMethod());
        $this->assertStringContainsString('wh-mine', (string) $this->history[1]['request']->getUri());
        $this->assertTrue($this->jsonBodyOf(1)['whatsapp_webhook']['active'], 'adopting must also un-pause');
        $this->assertFalse(
            $this->jsonBodyOf(1)['whatsapp_webhook']['buffer_enabled'],
            'buffering must be switched off explicitly even when adopting an auto-paused webhook'
        );
    }

    /**
     * A URL that merely contains ours as a substring is somebody else's.
     * url_contains is a server-side *substring* filter, so this response is
     * one Kapso can genuinely return.
     */
    public function test_a_url_that_only_contains_ours_as_a_substring_is_not_ours()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                ['id' => 'wh-other', 'url' => $url.'/proxy', 'active' => true],
            ]])),
            new Response(201, [], json_encode(['data' => ['id' => 'wh-ours', 'active' => true, 'url' => $url]])),
        ]);

        $account = $this->makeAccount();
        (new WebhookRegistrar($account))->register();

        $this->assertSame('wh-ours', $account->fresh()->webhook_id);
        $this->assertSame('POST', $this->history[1]['request']->getMethod());
    }

    /**
     * After a domain change our stored webhook no longer matches the current
     * URL. Moving it beats orphaning it and creating a second one.
     */
    public function test_a_previously_registered_webhook_is_moved_when_the_install_url_changes()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => ['id' => 'wh-old', 'url' => 'https://old.example.com/kapso-whatsapp/webhook', 'active' => true]])),
            new Response(200, [], json_encode(['data' => ['id' => 'wh-old', 'url' => $url, 'active' => true]])),
        ]);

        $account              = $this->makeAccount();
        $account->webhook_id  = 'wh-old';
        $account->webhook_url = 'https://old.example.com/kapso-whatsapp/webhook';
        $account->save();

        (new WebhookRegistrar($account))->register();

        $account = $account->fresh();
        $this->assertSame('wh-old', $account->webhook_id);
        $this->assertSame($url, $account->webhook_url);
        $this->assertSame('PATCH', $this->history[1]['request']->getMethod());
        $this->assertSame($url, $this->jsonBodyOf(1)['whatsapp_webhook']['url']);
    }

    public function test_a_stored_webhook_deleted_in_kapso_is_re_created()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(404, [], json_encode(['error' => 'Not found'])),               // GET stored id
            new Response(200, [], json_encode(['data' => []])),                         // list: none of ours
            new Response(201, [], json_encode(['data' => ['id' => 'wh-fresh', 'active' => true, 'url' => $url]])),
        ]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-gone';
        $account->save();

        (new WebhookRegistrar($account))->register();

        $this->assertSame('wh-fresh', $account->fresh()->webhook_id);
    }

    public function test_a_rejected_api_key_leaves_the_stored_state_untouched()
    {
        $account                 = $this->makeAccount();
        $account->webhook_id     = 'wh-live';
        $account->webhook_secret = 'old-secret';
        $account->save();

        // A stored webhook_id sends findOwnWebhook() straight to the
        // GET-by-id path, so a single queued response is enough: the 401
        // must be rethrown before any list/create/update call is made.
        $this->fakeResponses([new Response(401, [], json_encode(['error' => 'Invalid API key']))]);

        try {
            (new WebhookRegistrar($account))->register();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
        }

        $account = $account->fresh();
        $this->assertSame('wh-live', $account->webhook_id, 'a failed registration must not lose the stored webhook id');
        $this->assertSame('old-secret', $account->webhook_secret, 'a failed registration must not burn the working secret');
    }

    /**
     * The more dangerous shape than an outright auth rejection: the list
     * call succeeds (so findOwnWebhook() has already talked to Kapso once)
     * and only the following create fails. register() must still write
     * nothing until the create/update call itself has succeeded.
     */
    public function test_a_server_error_on_create_after_a_successful_list_leaves_the_stored_secret_untouched()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => []])),                          // list: none of ours
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),     // create fails
        ]);

        $account                 = $this->makeAccount();
        $account->webhook_secret = 'old-secret';
        $account->save();

        try {
            (new WebhookRegistrar($account))->register();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(500, $e->getHttpStatus());
        }

        $account = $account->fresh();
        $this->assertNull($account->webhook_id);
        $this->assertSame('old-secret', $account->webhook_secret, 'a failed create must not burn the working secret');
    }

    /**
     * The non-404 rethrow in findOwnWebhook() is what stops a transient
     * server error on the id fetch from falling through to listing and
     * creating a duplicate webhook. Pin it down so widening that catch later
     * doesn't silently regress.
     */
    public function test_a_server_error_fetching_the_stored_webhook_stops_before_any_further_request()
    {
        $this->fakeResponses([new Response(500, [], json_encode(['error' => 'Internal Server Error']))]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-live';
        $account->save();

        try {
            (new WebhookRegistrar($account))->register();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(500, $e->getHttpStatus());
        }

        $this->assertCount(1, $this->history, 'a transient error fetching our own webhook must not fall through to listing/creating a duplicate');
    }

    public function test_the_webhook_url_is_this_installs_own_endpoint()
    {
        $this->assertSame(route('kapsowhatsapp.webhook'), WebhookRegistrar::webhookUrl());
    }
}

<?php

namespace Modules\KapsoWhatsApp\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * These exercise the real Guzzle path (URI, headers, JSON envelope, error
 * mapping) against a MockHandler. Nothing here touches the database.
 */
class KapsoClientPlatformTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The API key is a module-wide setting, not a per-account attribute,
        // so it is configured once here rather than on each account() call.
        Settings::setApiKey('project-secret-key');
    }

    protected function account(): KapsoAccount
    {
        $account                  = new KapsoAccount();
        $account->phone_number_id = '15550001111';

        return $account;
    }

    protected function clientWithHistory(array $queue, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    public function test_listing_webhooks_hits_the_phone_number_scoped_path_with_the_api_key()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(200, [], json_encode(['data' => [['id' => 'wh-1', 'url' => 'https://a/hook']]])),
        ], $history);

        $webhooks = (new KapsoClient($this->account(), $client))
            ->listPhoneNumberWebhooks('https://a/hook');

        $this->assertSame([['id' => 'wh-1', 'url' => 'https://a/hook']], $webhooks);

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(
            'https://api.kapso.ai/platform/v1/whatsapp/phone_numbers/15550001111/webhooks',
            (string) $request->getUri()->withQuery('')
        );
        $this->assertStringContainsString('url_contains=', $request->getUri()->getQuery());
        $this->assertSame('project-secret-key', $request->getHeaderLine('X-API-Key'));
    }

    public function test_creating_a_webhook_wraps_the_attributes_in_the_whatsapp_webhook_envelope()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(201, [], json_encode(['data' => ['id' => 'wh-9', 'active' => true]])),
        ], $history);

        $webhook = (new KapsoClient($this->account(), $client))
            ->createPhoneNumberWebhook(['url' => 'https://a/hook', 'events' => ['whatsapp.message.received']]);

        $this->assertSame('wh-9', $webhook['id']);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['whatsapp_webhook'], array_keys($body));
        $this->assertSame('https://a/hook', $body['whatsapp_webhook']['url']);
    }

    public function test_updating_a_webhook_patches_the_phone_number_scoped_path()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(200, [], json_encode(['data' => ['id' => 'wh-9', 'active' => true]])),
        ], $history);

        (new KapsoClient($this->account(), $client))->updatePhoneNumberWebhook('wh-9', ['active' => true]);

        $request = $history[0]['request'];
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame(
            'https://api.kapso.ai/platform/v1/whatsapp/phone_numbers/15550001111/webhooks/wh-9',
            (string) $request->getUri()
        );
    }

    public function test_deliveries_are_requested_for_one_webhook_and_errors_only()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(200, [], json_encode(['data' => [['event' => 'x', 'status' => 'failed', 'response_status' => 403]]])),
        ], $history);

        $deliveries = (new KapsoClient($this->account(), $client))->listWebhookDeliveries('wh-9');

        $this->assertSame(403, $deliveries[0]['response_status']);

        $query = $history[0]['request']->getUri()->getQuery();
        $this->assertStringContainsString('webhook_id=wh-9', $query);
        $this->assertStringContainsString('errors_only=true', $query);
        $this->assertStringContainsString('period=24h', $query);
    }

    /**
     * The rule the whole feature hangs on: a rejected key must produce a
     * message that tells the admin to supply a valid API key -- never a
     * fallback instruction to register the webhook by hand.
     */
    public function test_a_401_demands_a_valid_api_key()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(401, [], json_encode(['error' => 'Invalid API key'])),
        ], $history);

        try {
            (new KapsoClient($this->account(), $client))->listPhoneNumberWebhooks();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertStringContainsString('API key', $e->getMessage());
            $this->assertStringNotContainsString('curl', strtolower($e->getMessage()));
        }
    }

    public function test_a_404_names_the_phone_number_id_as_the_suspect()
    {
        $history = [];
        $client  = $this->clientWithHistory([new Response(404, [], json_encode(['error' => 'Not found']))], $history);

        $this->expectException(KapsoApiException::class);
        $this->expectExceptionMessageMatches('/Phone Number ID/i');

        (new KapsoClient($this->account(), $client))->listPhoneNumberWebhooks();
    }

    public function test_a_422_surfaces_kapsos_own_message_with_any_markup_stripped()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(422, [], json_encode(['error' => '<b>url</b> must be https'])),
        ], $history);

        try {
            (new KapsoClient($this->account(), $client))->createPhoneNumberWebhook(['url' => 'x']);
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertStringContainsString('url must be https', $e->getMessage());
            $this->assertStringNotContainsString('<b>', $e->getMessage());
        }
    }

    public function test_a_transport_failure_becomes_a_kapso_exception_with_no_http_status()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new ConnectException('Connection refused', new Request('GET', 'https://api.kapso.ai/')),
        ], $history);

        try {
            (new KapsoClient($this->account(), $client))->listPhoneNumberWebhooks();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(0, $e->getHttpStatus());
            $this->assertStringContainsString('Could not reach Kapso', $e->getMessage());
        }
    }

    public function test_a_missing_api_key_fails_before_any_request_is_made()
    {
        $history = [];
        $client  = $this->clientWithHistory([], $history);

        $account = $this->account();
        Settings::setApiKey(null);

        try {
            (new KapsoClient($account, $client))->listPhoneNumberWebhooks();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertStringContainsString('API key', $e->getMessage());
        }

        $this->assertCount(0, $history, 'no HTTP request may be attempted without an API key');
    }

    public function test_the_fake_http_seam_is_used_when_no_client_is_injected()
    {
        $history = [];
        KapsoClient::fakeHttp($this->clientWithHistory([
            new Response(200, [], json_encode(['data' => []])),
        ], $history));

        (new KapsoClient($this->account()))->listPhoneNumberWebhooks();

        $this->assertCount(1, $history);
    }

    public function test_listing_phone_numbers_requests_a_full_page_and_returns_the_records()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(200, [], json_encode(['data' => [['phone_number_id' => '111']]])),
        ], $history);

        $numbers = (new KapsoClient($this->account(), $client))->listPhoneNumbers();

        $this->assertSame([['phone_number_id' => '111']], $numbers);

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(
            'https://api.kapso.ai/platform/v1/whatsapp/phone_numbers',
            (string) $request->getUri()->withQuery('')
        );
        $this->assertStringContainsString('per_page=100', $request->getUri()->getQuery());
    }

    /**
     * Kapso pages this endpoint at 20 by default; a project with more numbers
     * than one page must not silently show a truncated list.
     */
    public function test_listing_phone_numbers_follows_pagination()
    {
        $full = array_map(function ($i) {
            return ['phone_number_id' => (string) $i];
        }, range(1, 100));

        $history = [];
        $client  = $this->clientWithHistory([
            new Response(200, [], json_encode(['data' => $full])),
            new Response(200, [], json_encode(['data' => [['phone_number_id' => '101']]])),
        ], $history);

        $numbers = (new KapsoClient($this->account(), $client))->listPhoneNumbers();

        $this->assertCount(101, $numbers);
        $this->assertCount(2, $history);
        $this->assertStringContainsString('page=2', $history[1]['request']->getUri()->getQuery());
    }

    public function test_listing_phone_numbers_stops_at_the_page_cap()
    {
        $full = array_map(function ($i) {
            return ['phone_number_id' => (string) $i];
        }, range(1, 100));

        $history = [];
        $client  = $this->clientWithHistory(array_fill(0, 12, new Response(200, [], json_encode(['data' => $full]))), $history);

        (new KapsoClient($this->account(), $client))->listPhoneNumbers();

        $this->assertLessThanOrEqual(10, count($history), 'a server that always returns a full page must not loop forever');
    }
}

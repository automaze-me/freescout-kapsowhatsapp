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
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * KapsoClient::fake() short-circuits before any Guzzle code runs, so the
 * feature tests that use it never exercise the real HTTP path. These tests
 * point KapsoClient at a real Guzzle Client wired to a MockHandler instead,
 * to verify the one property this class exists to guarantee: Kapso media
 * URLs are fetched with the project's API key, since they are not public.
 */
class KapsoClientTest extends TestCase
{
    /**
     * No ->save() (no DB round trip needed): the setApiKeyAttribute()/
     * getApiKeyAttribute() mutator/accessor pair encrypts and decrypts in
     * memory off the app key alone.
     */
    protected function account(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->api_key = 'project-secret-key';

        return $account;
    }

    protected function clientWithHistory(array $queue, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    public function test_it_downloads_media_with_the_account_api_key_in_the_header()
    {
        $history = [];
        $client  = $this->clientWithHistory([new Response(200, [], 'raw-media-bytes')], $history);

        $bytes = (new KapsoClient($this->account(), $client))->downloadMedia('https://api.kapso.ai/media/abc');

        $this->assertSame('raw-media-bytes', $bytes);
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api.kapso.ai/media/abc', (string) $request->getUri());
        $this->assertSame('project-secret-key', $request->getHeaderLine('X-API-Key'));
    }

    public function test_a_non_200_response_returns_null()
    {
        $history = [];
        $client  = $this->clientWithHistory([new Response(404, [], 'not found')], $history);

        $bytes = (new KapsoClient($this->account(), $client))->downloadMedia('https://api.kapso.ai/media/missing');

        $this->assertNull($bytes);
    }

    public function test_a_transport_exception_returns_null_instead_of_throwing()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new ConnectException('Connection refused', new Request('GET', 'https://api.kapso.ai/media/abc')),
        ], $history);

        $bytes = (new KapsoClient($this->account(), $client))->downloadMedia('https://api.kapso.ai/media/abc');

        $this->assertNull($bytes);
    }

    public function test_a_null_url_short_circuits_without_making_a_request()
    {
        $history = [];
        $client  = $this->clientWithHistory([], $history);

        $bytes = (new KapsoClient($this->account(), $client))->downloadMedia(null);

        $this->assertNull($bytes);
        $this->assertCount(0, $history);
    }

    /**
     * The single constructor argument form (no injected client) must keep
     * working exactly as before: a real Guzzle Client with a 30s timeout,
     * so a slow/unresponsive Kapso media host cannot hang the queue worker.
     */
    public function test_the_production_default_client_has_a_30_second_timeout()
    {
        $client = new KapsoClient($this->account());

        $property = new \ReflectionProperty(KapsoClient::class, 'client');
        $property->setAccessible(true);
        $httpClient = $property->getValue($client);

        $this->assertInstanceOf(Client::class, $httpClient);
        $this->assertSame(30, $httpClient->getConfig('timeout'));
    }
}

<?php

namespace Modules\KapsoWhatsApp\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

class KapsoClientMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_sending_posts_to_the_meta_proxy_with_the_api_key()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(200, [], json_encode(['messages' => [['id' => 'wamid.SENT1']]])),
        ], $history);

        $payload  = ['messaging_product' => 'whatsapp', 'to' => '491771234567', 'type' => 'text', 'text' => ['body' => 'Hi']];
        $response = (new KapsoClient($this->account(), $client))->sendWhatsAppMessage($payload);

        $this->assertSame('wamid.SENT1', KapsoClient::extractWamid($response));

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'https://api.kapso.ai/meta/whatsapp/v24.0/15550001111/messages',
            (string) $request->getUri()
        );
        $this->assertSame('project-secret-key', $request->getHeaderLine('X-API-Key'));
        $this->assertSame($payload, json_decode((string) $request->getBody(), true));
    }

    public function test_a_meta_error_maps_through_the_shared_error_path()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(401, [], json_encode(['error' => 'Invalid API key'])),
        ], $history);

        try {
            (new KapsoClient($this->account(), $client))->sendWhatsAppMessage(['to' => 'x']);
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertStringContainsString('API key', $e->getMessage());
        }
    }

    /**
     * Meta-shaped errors are {"error":{"message":...}} objects, not the
     * Platform API's {"error":"string"}. The mapper must surface the message
     * rather than "no details given".
     */
    public function test_a_meta_object_error_surfaces_its_message()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(400, [], json_encode(['error' => ['message' => '(#131030) Recipient phone number not in allowed list', 'code' => 131030]])),
        ], $history);

        try {
            (new KapsoClient($this->account(), $client))->sendWhatsAppMessage(['to' => 'x']);
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertStringContainsString('131030', $e->getMessage());
        }
    }

    public function test_extract_wamid_is_null_safe()
    {
        $this->assertNull(KapsoClient::extractWamid([]));
        $this->assertNull(KapsoClient::extractWamid(['messages' => []]));
        $this->assertNull(KapsoClient::extractWamid(['messages' => [['no_id' => 1]]]));
        $this->assertNull(KapsoClient::extractWamid(['messages' => 'not-a-list']));
    }

    public function test_mark_read_posts_the_read_status_payload()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new Response(200, [], json_encode(['success' => true])),
        ], $history);

        (new KapsoClient($this->account(), $client))->markMessageRead('wamid.INBOUND9');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => 'wamid.INBOUND9',
        ], $body);
    }

    public function test_a_transport_failure_still_becomes_a_kapso_exception()
    {
        $history = [];
        $client  = $this->clientWithHistory([
            new \GuzzleHttp\Exception\ConnectException('refused', new \GuzzleHttp\Psr7\Request('POST', 'x')),
        ], $history);

        $this->expectException(KapsoApiException::class);
        (new KapsoClient($this->account(), $client))->sendWhatsAppMessage(['to' => 'x']);
    }
}

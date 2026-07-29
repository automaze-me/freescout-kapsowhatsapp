<?php

namespace Modules\KapsoWhatsApp\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;

class KapsoClient
{
    const PLATFORM_BASE = 'https://api.kapso.ai/platform/v1';

    protected $account;
    protected $client;

    /**
     * Test seam. Set by KapsoClient::fake() so feature tests never make real
     * HTTP calls; null in production.
     */
    protected static $fakeHandler = null;

    /**
     * Test seam for the Platform API path. Unlike fake(), this replaces the
     * Guzzle transport rather than short-circuiting before it, so tests still
     * exercise the real URI/header/envelope building. Set by fakeHttp(); null
     * in production. Feature tests need this because the objects that build a
     * KapsoClient (WebhookRegistrar, the admin controller) construct it
     * themselves and have nowhere to inject one.
     */
    protected static $fakeHttpClient = null;

    /**
     * $client is injectable so tests can point downloadMedia() at a Guzzle
     * MockHandler and assert on the real HTTP path (headers, URI, timeout,
     * error handling) instead of only exercising KapsoClient::fake(). The
     * production default — a real Guzzle Client with a 30s timeout — is
     * unchanged when no client is passed.
     */
    public function __construct(KapsoAccount $account, ClientInterface $client = null)
    {
        $this->account = $account;
        $this->client  = $client ?: (self::$fakeHttpClient ?: new Client(['timeout' => 30]));
    }

    public static function fake(callable $handler)
    {
        self::$fakeHandler = $handler;
    }

    public static function clearFake()
    {
        self::$fakeHandler = null;
    }

    public static function fakeHttp(ClientInterface $client)
    {
        self::$fakeHttpClient = $client;
    }

    public static function clearFakeHttp()
    {
        self::$fakeHttpClient = null;
    }

    /**
     * Kapso media URLs are not public: they require the project API key.
     * Returns raw bytes, or null when the download fails for any reason —
     * losing an attachment must never lose the message.
     */
    public function downloadMedia($url)
    {
        if (!$url) {
            return null;
        }

        if (self::$fakeHandler) {
            return call_user_func(self::$fakeHandler, $url);
        }

        try {
            $response = $this->client->request('GET', $url, [
                'headers' => ['X-API-Key' => $this->account->api_key],
            ]);

            if ($response->getStatusCode() !== 200) {
                \Log::warning('[KapsoWhatsApp] Media download returned '.$response->getStatusCode(), ['url' => $url]);

                return null;
            }

            return (string) $response->getBody();
        } catch (\Exception $e) {
            \Log::warning('[KapsoWhatsApp] Media download failed: '.$e->getMessage(), ['url' => $url]);

            return null;
        }
    }

    public function listPhoneNumberWebhooks($urlContains = null)
    {
        $query = [];

        if ($urlContains !== null && $urlContains !== '') {
            $query['url_contains'] = $urlContains;
        }

        return $this->dataList($this->platformRequest('GET', $this->webhooksPath(), null, $query));
    }

    public function getPhoneNumberWebhook($webhookId)
    {
        return $this->dataObject($this->platformRequest('GET', $this->webhooksPath().'/'.rawurlencode($webhookId)));
    }

    public function createPhoneNumberWebhook(array $attributes)
    {
        return $this->dataObject($this->platformRequest(
            'POST',
            $this->webhooksPath(),
            ['whatsapp_webhook' => $attributes]
        ));
    }

    public function updatePhoneNumberWebhook($webhookId, array $attributes)
    {
        return $this->dataObject($this->platformRequest(
            'PATCH',
            $this->webhooksPath().'/'.rawurlencode($webhookId),
            ['whatsapp_webhook' => $attributes]
        ));
    }

    /**
     * Failed delivery attempts for one webhook. This is what makes a paused
     * webhook explainable: response_status is the HTTP code this FreeScout
     * install returned to Kapso.
     */
    public function listWebhookDeliveries($webhookId, $period = '24h', $limit = 20)
    {
        return $this->dataList($this->platformRequest('GET', '/webhook_deliveries', null, [
            'webhook_id'  => $webhookId,
            'period'      => $period,
            'errors_only' => 'true',
            'limit'       => $limit,
        ]));
    }

    protected function webhooksPath()
    {
        return '/whatsapp/phone_numbers/'.rawurlencode((string) $this->account->phone_number_id).'/webhooks';
    }

    protected function dataList(array $response)
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    protected function dataObject(array $response)
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * http_errors is off so every failure -- 401, 404, 422, 5xx -- comes back
     * through one place and turns into an admin-readable message. Timeouts are
     * deliberately short: this runs inside an admin page request, not a queue
     * worker.
     */
    protected function platformRequest($method, $path, array $body = null, array $query = [])
    {
        $apiKey = $this->account->api_key;

        if (!$apiKey) {
            throw new KapsoApiException(
                __('This account has no Kapso API key. Enter a valid Kapso API key for this account and save, then try again.')
            );
        }

        $options = [
            'headers' => [
                'X-API-Key' => $apiKey,
                'Accept'    => 'application/json',
            ],
            'http_errors'     => false,
            'connect_timeout' => 5,
            'timeout'         => 15,
        ];

        if ($query) {
            $options['query'] = $query;
        }

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->client->request($method, self::PLATFORM_BASE.$path, $options);
        } catch (\Exception $e) {
            throw new KapsoApiException(
                __('Could not reach Kapso: :error', ['error' => $this->sanitise($e->getMessage())]),
                0,
                $e
            );
        }

        $status  = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);

        if ($status < 200 || $status >= 300) {
            throw new KapsoApiException($this->errorMessage($status, $decoded), $status);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * These strings are the module's answer to "registration failed". Each one
     * names what the admin has to change. None of them ever suggests
     * registering the webhook by hand.
     */
    protected function errorMessage($status, $decoded)
    {
        $detail = (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error']))
            ? $this->sanitise($decoded['error'])
            : '';

        switch ($status) {
            case 401:
            case 403:
                return __('Kapso rejected the API key. Enter a valid Kapso API key for this account.');
            case 404:
                return __('Kapso does not recognise this Phone Number ID for the project this API key belongs to. Check the Phone Number ID and the API key.');
            case 422:
                return __('Kapso rejected the request: :error', ['error' => $detail ?: __('validation failed')]);
            default:
                return __('Kapso returned an unexpected error (HTTP :status): :error', [
                    'status' => $status,
                    'error'  => $detail ?: __('no details given'),
                ]);
        }
    }

    /**
     * Messages built here are flashed through flash_error_floating, which
     * renders with safe_raw_html. Strip markup and control characters from
     * anything Kapso sent us rather than escaping at the view, so the message
     * stays readable instead of arriving full of &#039; entities.
     */
    protected function sanitise($text)
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', strip_tags((string) $text));

        return trim(mb_substr($text, 0, 300));
    }
}

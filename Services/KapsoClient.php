<?php

namespace Modules\KapsoWhatsApp\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;

class KapsoClient
{
    const PLATFORM_BASE = 'https://api.kapso.ai/platform/v1';
    const META_BASE = 'https://api.kapso.ai/meta/whatsapp/v24.0';

    /**
     * Kapso pages the phone-numbers endpoint (default 20), so a project with
     * more numbers than one page would otherwise present the admin with a
     * silently truncated list. NUMBERS_PAGE_CAP bounds the loop against a
     * server that keeps answering with a full page.
     */
    const NUMBERS_PER_PAGE = 100;
    const NUMBERS_PAGE_CAP = 10;

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
     *
     * SSRF guard: $url comes straight from an inbound webhook payload
     * (kapso.media_url / media_data.url). The payload is HMAC-authenticated,
     * but this is defense in depth — a forged or compromised payload could
     * otherwise point this server-side request (which carries the install's
     * X-API-Key header) at an internal target: localhost, a cloud metadata
     * IP, a LAN host — leaking the API key to whatever answers. Before any
     * request — real or faked — the URL must be plain https to a
     * non-private, non-reserved host, or it is refused and treated like any
     * other failed download. Outbound WhatsApp links are always built by
     * this app from APP_URL, never taken from a webhook, so the outbound
     * path never calls this method and never passes through this guard.
     */
    public function downloadMedia($url)
    {
        if (!$url) {
            return null;
        }

        if (!self::isMediaUrlSafe($url)) {
            \Log::warning('[KapsoWhatsApp] Refused to download media: unsafe URL', ['url' => $url]);

            return null;
        }

        if (self::$fakeHandler) {
            return call_user_func(self::$fakeHandler, $url);
        }

        try {
            $response = $this->client->request('GET', $url, [
                'headers' => ['X-API-Key' => Settings::apiKey()],
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

    /**
     * The two checks downloadMedia() requires before it will fetch a URL:
     * exactly https (Kapso always serves media over TLS; http:// and any
     * non-http(s) scheme such as file:// or gopher:// is refused), and a
     * public, non-reserved host per core's Helper::checkUrlIpAndHost(),
     * which rejects loopback/private/link-local/metadata addresses and the
     * app's own host. Guarded with method_exists() so the module still runs
     * -- with today's unguarded behaviour -- on a core version that
     * predates the helper.
     */
    protected static function isMediaUrlSafe($url)
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        if (method_exists(\Helper::class, 'checkUrlIpAndHost') && !\Helper::checkUrlIpAndHost($url)) {
            return false;
        }

        return true;
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

    /**
     * Every WhatsApp number in the project this API key belongs to.
     */
    public function listPhoneNumbers()
    {
        $numbers = [];

        for ($page = 1; $page <= self::NUMBERS_PAGE_CAP; $page++) {
            $batch = $this->dataList($this->platformRequest('GET', '/whatsapp/phone_numbers', null, [
                'per_page' => self::NUMBERS_PER_PAGE,
                'page'     => $page,
            ]));

            $numbers = array_merge($numbers, $batch);

            if (count($batch) < self::NUMBERS_PER_PAGE) {
                break;
            }
        }

        return $numbers;
    }

    /**
     * Sends one WhatsApp message through Kapso's Meta-compatible proxy.
     * Returns the decoded response (contains the wamid on success); throws
     * KapsoApiException on any non-2xx response or transport failure.
     */
    public function sendWhatsAppMessage(array $payload)
    {
        return $this->metaRequest('POST', $this->messagesPath(), $payload);
    }

    /**
     * Pulls the wamid Meta assigned to a just-sent message out of the send
     * response. Total on malformed input: anything short of a well-formed
     * {"messages":[{"id":"..."}]} shape returns null rather than throwing,
     * since callers use this to decide what to persist, not to validate
     * Kapso's response.
     */
    public static function extractWamid($response)
    {
        if (!is_array($response) || !isset($response['messages']) || !is_array($response['messages'])) {
            return null;
        }

        $first = reset($response['messages']);

        return (is_array($first) && isset($first['id']) && is_string($first['id'])) ? $first['id'] : null;
    }

    /**
     * Stage 3c: the project's WhatsApp message templates, narrowed to the
     * ones this module's send path can actually fill. Meta returns every
     * template regardless of status or shape (media headers, dynamic
     * buttons, carousels, ...); this slice is text-body only -- the UI must
     * never offer something the send path cannot fill -- so eligibility is
     * enforced here, server-side, not left to the picker:
     *
     *   - status must be APPROVED (draft/pending/rejected templates cannot
     *     be sent by anyone, agent-facing or not);
     *   - exactly one BODY component, with a plain string `text`;
     *   - every non-BODY component must be parameter-free: a HEADER is fine
     *     only when it is TEXT format and its own text carries no `{{`
     *     placeholder (a media header has nothing this module could
     *     supply); a FOOTER is always fine (Meta only allows static footer
     *     text); BUTTONS are fine only when no button's text/url contains
     *     `{{` (a dynamic button URL is a parameter like any other).
     *
     * `variables` is the highest `{{n}}` placeholder index found in the
     * BODY text (0 when the body has none) -- this is what tells the
     * caller how many text inputs the picker needs to render.
     *
     * Total parser: every array access is guarded and any template whose
     * shape does not match the above (including a wholly malformed
     * response) is silently skipped rather than thrown -- one unexpected
     * template must never take down the whole list, and a malformed
     * response as a whole degrades to an empty list (never an exception).
     */
    public function listMessageTemplates()
    {
        $templates = $this->dataList($this->metaRequest('GET', $this->templatesPath()));

        $eligible = [];

        foreach ($templates as $template) {
            $normalised = $this->normaliseTemplate($template);

            if ($normalised !== null) {
                $eligible[] = $normalised;
            }
        }

        return $eligible;
    }

    /**
     * Marks an inbound message read (blue ticks for the customer). Throws on
     * failure like every other call here -- this is best-effort only in the
     * sense that callers may choose to swallow the exception, not because
     * this method hides it.
     */
    public function markMessageRead($wamid)
    {
        $this->metaRequest('POST', $this->messagesPath(), [
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => (string) $wamid,
        ]);
    }

    protected function webhooksPath()
    {
        return '/whatsapp/phone_numbers/'.rawurlencode((string) $this->account->phone_number_id).'/webhooks';
    }

    /**
     * Meta-proxy counterpart of webhooksPath(): both sendWhatsAppMessage()
     * and markMessageRead() POST to the same `{phone_number_id}/messages`
     * endpoint, one with a message payload and the other with a read-status
     * payload.
     */
    protected function messagesPath()
    {
        return '/'.rawurlencode((string) $this->account->phone_number_id).'/messages';
    }

    /**
     * Meta-proxy counterpart of webhooksPath() for Stage 3c's template
     * list: the business account, not the phone number, owns the project's
     * templates (a project can have several numbers sharing one set of
     * approved templates).
     */
    protected function templatesPath()
    {
        return '/'.rawurlencode((string) $this->account->business_account_id).'/message_templates';
    }

    /**
     * One entry of listMessageTemplates()'s raw `data[]`, turned into
     * `['name', 'language', 'body', 'variables']` or null when the entry is
     * not an eligible text-body template -- see listMessageTemplates()'s
     * docblock for the eligibility rules this enforces. Guards every array
     * access; an unrecognised component type is treated the same as a
     * disqualifying one (returns null for the whole template) rather than
     * being ignored, since a shape this parser does not understand is a
     * shape it cannot promise the send path can fill either.
     */
    protected function normaliseTemplate($template)
    {
        if (!is_array($template)) {
            return null;
        }

        if (!isset($template['status']) || $template['status'] !== 'APPROVED') {
            return null;
        }

        if (!isset($template['name']) || !is_string($template['name']) || $template['name'] === '') {
            return null;
        }

        if (!isset($template['language']) || !is_string($template['language']) || $template['language'] === '') {
            return null;
        }

        if (!isset($template['components']) || !is_array($template['components'])) {
            return null;
        }

        $body = null;

        foreach ($template['components'] as $component) {
            if (!is_array($component) || !isset($component['type']) || !is_string($component['type'])) {
                return null;
            }

            switch (strtoupper($component['type'])) {
                case 'BODY':
                    // Exactly one BODY component is expected; a second one
                    // is a shape this module does not understand.
                    if ($body !== null) {
                        return null;
                    }
                    if (!isset($component['text']) || !is_string($component['text'])) {
                        return null;
                    }
                    // Meta also approves *named* parameters
                    // ({{customer_name}}); the send path only builds
                    // positional {{n}} ones and the variable count only
                    // sees {{n}}, so any `{{` left after removing the
                    // positional placeholders is a parameter this module
                    // cannot fill.
                    if (strpos(preg_replace('/\{\{\d+\}\}/', '', $component['text']), '{{') !== false) {
                        return null;
                    }
                    $body = $component['text'];
                    break;

                case 'HEADER':
                    $format = isset($component['format']) && is_string($component['format'])
                        ? strtoupper($component['format']) : null;
                    if ($format !== 'TEXT') {
                        // Media header (IMAGE/VIDEO/DOCUMENT/LOCATION) or an
                        // unrecognised format -- this send path has nothing
                        // to supply for it.
                        return null;
                    }
                    $headerText = isset($component['text']) && is_string($component['text'])
                        ? $component['text'] : '';
                    if (strpos($headerText, '{{') !== false) {
                        return null;
                    }
                    break;

                case 'FOOTER':
                    // Meta only allows static footer text -- no parameters
                    // are possible here, nothing to check.
                    break;

                case 'BUTTONS':
                    $buttons = isset($component['buttons']) && is_array($component['buttons'])
                        ? $component['buttons'] : [];
                    foreach ($buttons as $button) {
                        if (!is_array($button)) {
                            return null;
                        }
                        $buttonText = isset($button['text']) && is_string($button['text']) ? $button['text'] : '';
                        $buttonUrl  = isset($button['url']) && is_string($button['url']) ? $button['url'] : '';
                        if (strpos($buttonText, '{{') !== false || strpos($buttonUrl, '{{') !== false) {
                            return null;
                        }
                    }
                    break;

                default:
                    return null;
            }
        }

        if ($body === null) {
            return null;
        }

        return [
            'name'      => $template['name'],
            'language'  => $template['language'],
            'body'      => $body,
            'variables' => $this->maxTemplateVariable($body),
        ];
    }

    /**
     * The highest numbered `{{n}}` placeholder in a template BODY text, 0
     * when it has none. Named parameters (`{{customer_name}}`, Meta's newer
     * template syntax) are deliberately out of scope -- Stage 3c's send
     * payload only ever builds positional `{{n}}` parameters. Max, not
     * count: a GAPPED body ({{1}} and {{3}}, no {{2}}) would render three
     * inputs with one unused -- accepted, because Meta does not approve
     * gapped templates, so the case is unreachable through the APPROVED
     * filter above.
     */
    protected function maxTemplateVariable($body)
    {
        if (!preg_match_all('/\{\{(\d+)\}\}/', $body, $matches)) {
            return 0;
        }

        return (int) max(array_map('intval', $matches[1]));
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
     * Thin wrapper kept for every existing Platform API caller: same
     * signature, same behaviour, just delegates URL-building to apiRequest().
     */
    protected function platformRequest($method, $path, array $body = null, array $query = [])
    {
        return $this->apiRequest($method, self::PLATFORM_BASE.$path, $body, $query);
    }

    /**
     * Meta-proxy counterpart of platformRequest(). No query-string callers
     * exist yet, so it only forwards $body.
     */
    protected function metaRequest($method, $path, array $body = null)
    {
        return $this->apiRequest($method, self::META_BASE.$path, $body);
    }

    /**
     * http_errors is off so every failure -- 401, 404, 422, 5xx -- comes back
     * through one place and turns into an admin-readable message. Timeouts are
     * deliberately short: this call can run either inside an admin page
     * request (webhook management) or inside a queue worker (sending a
     * reply) -- a hung request must not stall either one.
     */
    protected function apiRequest($method, $url, array $body = null, array $query = [])
    {
        $apiKey = Settings::apiKey();

        if (!$apiKey) {
            throw new KapsoApiException(
                __('No Kapso API key is configured. Add one in Manage » WhatsApp Accounts and try again.')
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
            $response = $this->client->request($method, $url, $options);
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
     * These strings are the module's answer to "the API call failed", shared
     * by both the Platform API (webhook management) and the Meta proxy
     * (sending). Each one names what the admin has to change. None of them
     * ever suggests a manual fallback (registering the webhook by hand,
     * sending the message by hand).
     */
    protected function errorMessage($status, $decoded)
    {
        // Platform API errors are {"error":"string"}; Meta-proxy errors are
        // {"error":{"message":...,"code":...}} objects. Handle both shapes so
        // a send rejection surfaces its text instead of "no details given".
        $detail = '';
        if (is_array($decoded) && isset($decoded['error'])) {
            if (is_string($decoded['error'])) {
                $detail = $this->sanitise($decoded['error']);
            } elseif (is_array($decoded['error']) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $detail = $this->sanitise($decoded['error']['message']);
            }
        }

        switch ($status) {
            case 401:
            case 403:
                return __('Kapso rejected the API key. Enter a valid Kapso API key on the WhatsApp Accounts page.');
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

<?php

namespace Modules\KapsoWhatsApp\Services;

use GuzzleHttp\Client;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;

class KapsoClient
{
    const BASE_URL = 'https://api.kapso.ai';

    protected $account;

    /**
     * Test seam. Set by KapsoClient::fake() so feature tests never make real
     * HTTP calls; null in production.
     */
    protected static $fakeHandler = null;

    public function __construct(KapsoAccount $account)
    {
        $this->account = $account;
    }

    public static function fake(callable $handler)
    {
        self::$fakeHandler = $handler;
    }

    public static function clearFake()
    {
        self::$fakeHandler = null;
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
            $response = (new Client(['timeout' => 30]))->request('GET', $url, [
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
}

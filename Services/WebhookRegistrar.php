<?php

namespace Modules\KapsoWhatsApp\Services;

use Illuminate\Support\Str;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;

/**
 * Owns this install's Kapso webhook: creating it, adopting one that is
 * already pointed at us, and keeping its status visible.
 *
 * The one invariant everything here protects: a webhook is "ours" only if
 * this module registered it (we stored its id) or its URL is exactly this
 * install's webhook endpoint. Anything else on the same phone number belongs
 * to another system -- an n8n bridge, a second helpdesk -- and must never be
 * updated, disabled or deleted. That is why there is no reconcile/sync path
 * here and no call to DELETE anywhere in this module.
 */
class WebhookRegistrar
{
    const EVENTS = [
        'whatsapp.message.received',
        'whatsapp.message.sent',
        'whatsapp.message.failed',
    ];

    const PAYLOAD_VERSION = 'v2';

    protected $account;
    protected $client;

    public function __construct(KapsoAccount $account, KapsoClient $client = null)
    {
        $this->account = $account;
        $this->client  = $client ?: new KapsoClient($account);
    }

    public static function webhookUrl()
    {
        return route('kapsowhatsapp.webhook');
    }

    /**
     * Idempotent: safe to click twice, and safe to re-run after a partial
     * failure. Each run mints a fresh secret and hands Kapso the same value it
     * stores locally, so the two can never drift -- and re-running is the
     * documented cure if they ever do.
     */
    public function register()
    {
        $url    = self::webhookUrl();
        $secret = Str::random(48);

        $attributes = [
            'url'             => $url,
            'kind'            => 'kapso',
            'secret_key'      => $secret,
            'events'          => self::EVENTS,
            'payload_version' => self::PAYLOAD_VERSION,
            'active'          => true,
            // Buffered deliveries arrive as a batch envelope with no
            // top-level phone_number_id, which KapsoSignature rejects with a
            // 403 -- and ~15 minutes of 403s is exactly what makes Kapso
            // auto-pause the webhook. Assert it off rather than trusting a
            // remote default that may change.
            'buffer_enabled'  => false,
        ];

        $existing = $this->findOwnWebhook($url);

        $webhook = $existing
            ? $this->client->updatePhoneNumberWebhook($existing['id'], $attributes)
            : $this->client->createPhoneNumberWebhook($attributes);

        // Written only after Kapso confirmed. If this save were to fail, Kapso
        // would hold a secret we do not -- every delivery would then 403 until
        // someone registers again, which is precisely what re-running fixes.
        $this->account->webhook_id         = isset($webhook['id']) ? (string) $webhook['id'] : null;
        $this->account->webhook_url        = $url;
        $this->account->webhook_secret     = $secret;
        $this->account->webhook_active     = isset($webhook['active']) ? (bool) $webhook['active'] : true;
        $this->account->webhook_checked_at = now();
        $this->account->webhook_error      = null;
        $this->account->save();

        return $webhook;
    }

    /**
     * Prefer the webhook this module registered before, even when the install
     * URL has since changed: PATCHing it moves our own webhook instead of
     * leaving a stale one delivering into the void.
     */
    protected function findOwnWebhook($url)
    {
        if ($this->account->webhook_id) {
            try {
                $webhook = $this->client->getPhoneNumberWebhook($this->account->webhook_id);

                if (!empty($webhook['id'])) {
                    return $webhook;
                }
            } catch (KapsoApiException $e) {
                if ($e->getHttpStatus() !== 404) {
                    throw $e;
                }
                // Deleted in Kapso's dashboard. Fall through and re-create.
            }
        }

        // url_contains is a substring filter, so the exact comparison below is
        // what actually decides ownership -- "…/webhook/proxy" contains our
        // URL but is not ours.
        foreach ($this->client->listPhoneNumberWebhooks($url) as $webhook) {
            if (is_array($webhook) && isset($webhook['url']) && $webhook['url'] === $url) {
                return $webhook;
            }
        }

        return null;
    }
}

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

    /**
     * How old a status reading may be before the settings page refreshes it.
     * Low enough that a paused webhook surfaces on the next visit, high enough
     * that reloading the page is not one HTTP round trip per account.
     */
    const STALE_AFTER_MINUTES = 5;

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

        // A 204 No Content response (Kapso's PATCH can return one) decodes to
        // an empty $webhook, which must not be read as "no id" -- the id we
        // PATCHed against is still the correct one to store.
        $id = $webhook['id'] ?? ($existing['id'] ?? null);

        // Written only after Kapso confirmed. If this save were to fail, Kapso
        // would hold a secret we do not -- every delivery would then 403 until
        // someone registers again, which is precisely what re-running fixes.
        $this->account->webhook_id         = $id !== null ? (string) $id : null;
        $this->account->webhook_url        = $url;
        $this->account->webhook_secret     = $secret;
        // webhook_active is tri-state: null means "not known". A response
        // that omits `active` (e.g. an empty body from a 204) tells us
        // nothing about the current state -- optimistically writing true
        // would claim knowledge we don't have.
        $this->account->webhook_active     = isset($webhook['active']) ? (bool) $webhook['active'] : null;
        $this->account->webhook_checked_at = now();
        $this->account->webhook_error      = null;
        $this->account->save();

        return $webhook;
    }

    /**
     * Reads the webhook's current state from Kapso. Returns null when there is
     * nothing registered, or when the webhook has been deleted on their side.
     */
    public function refresh()
    {
        if (!$this->account->webhook_id) {
            return null;
        }

        try {
            $webhook = $this->client->getPhoneNumberWebhook($this->account->webhook_id);
        } catch (KapsoApiException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }

            $this->account->webhook_id         = null;
            $this->account->webhook_active     = null;
            $this->account->webhook_checked_at = now();
            $this->account->webhook_error      = __('The webhook this module registered no longer exists in Kapso. Register it again.');
            $this->account->save();

            return null;
        }

        $active = isset($webhook['active']) ? (bool) $webhook['active'] : null;

        $this->account->webhook_active     = $active;
        $this->account->webhook_url        = isset($webhook['url']) ? $webhook['url'] : $this->account->webhook_url;
        $this->account->webhook_checked_at = now();
        $this->account->webhook_error      = $active === false ? $this->pauseReason() : null;
        $this->account->save();

        return $webhook;
    }

    public function resume()
    {
        if (!$this->account->webhook_id) {
            throw new KapsoApiException(__('This account has no registered webhook yet. Register it first.'));
        }

        // Only the active flag: re-sending secret_key or events here would
        // rewrite settings the admin may have no reason to expect to change.
        $webhook = $this->client->updatePhoneNumberWebhook($this->account->webhook_id, ['active' => true]);

        $this->account->webhook_active     = isset($webhook['active']) ? (bool) $webhook['active'] : true;
        $this->account->webhook_checked_at = now();
        $this->account->webhook_error      = null;
        $this->account->save();

        return $webhook;
    }

    /**
     * Kapso does not say why it paused a webhook, so ask the delivery log.
     * response_status is the HTTP code this install returned, which is the
     * one fact that distinguishes "our endpoint is rejecting deliveries" from
     * "Kapso could not reach us at all".
     */
    protected function pauseReason()
    {
        try {
            $failures = $this->client->listWebhookDeliveries($this->account->webhook_id, '24h', 20);
        } catch (KapsoApiException $e) {
            $failures = [];
        }

        if (!$failures) {
            return __('Kapso has paused this webhook. Kapso pauses a webhook automatically after a run of failed deliveries and never resumes it on its own.');
        }

        $latest   = reset($failures);
        $response = (isset($latest['response_status']) && $latest['response_status'])
            ? __('HTTP :status', ['status' => (int) $latest['response_status']])
            : __('no response');

        return __('Kapso has paused this webhook after failed deliveries. :count failed in the last 24 hours; the most recent attempt got :response from this FreeScout.', [
            'count'    => count($failures),
            'response' => $response,
        ]);
    }

    /**
     * Kapso delivers from the public internet: a localhost, private-range or
     * dotless host can be registered successfully and still never receive a
     * delivery. Advisory only -- it warns, it never blocks, because only the
     * operator knows what is routable from outside.
     */
    public static function looksUnreachable($url)
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host) {
            return true;
        }

        $host = strtolower($host);

        if ($host === 'localhost' || substr($host, -6) === '.local' || strpos($host, '.') === false) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
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
        // URL but is not ours. A url match with no id would otherwise trigger
        // a PATCH to the collection endpoint instead of a clean create.
        foreach ($this->client->listPhoneNumberWebhooks($url) as $webhook) {
            if (is_array($webhook) && isset($webhook['url']) && $webhook['url'] === $url && !empty($webhook['id'])) {
                return $webhook;
            }
        }

        return null;
    }
}

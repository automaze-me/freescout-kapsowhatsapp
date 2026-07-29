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
     * How long since the last attempt (successful or not -- see
     * webhook_check_attempted_at) before the settings page will try Kapso
     * again for a given account. Low enough that a paused webhook surfaces on
     * the next visit, high enough that reloading the page is not one HTTP
     * round trip per account -- and, when Kapso itself is slow or down, not
     * one multi-second timeout per account either.
     */
    const STALE_AFTER_MINUTES = 5;

    protected $account;
    protected $client;

    public function __construct(KapsoAccount $account, KapsoClient $client = null)
    {
        $this->account = $account;
        $this->client  = $client ?: new KapsoClient($account);
    }

    /**
     * FreeScout core calls forceScheme('https') but never forceRootUrl(), so
     * a plain route('kapsowhatsapp.webhook') follows whatever host the
     * current request came in on -- including a secondary hostname an admin
     * happens to be browsing from (core supports multiple via
     * APP_TRUSTED_HOSTS). Registering that URL would PATCH the live webhook
     * to point at an address nobody else serves. The canonical
     * config('app.url') is what must be registered, regardless of how this
     * request arrived.
     */
    public static function webhookUrl()
    {
        $root = self::rootUrl();

        return $root !== null
            ? $root.route('kapsowhatsapp.webhook', [], false)
            : route('kapsowhatsapp.webhook');
    }

    /**
     * Scheme + host [+ port] from the canonical config('app.url'),
     * deliberately dropping any path component: Helper::getSubdirectory()
     * derives this module's route prefix from that very same path, so the
     * relative route returned by route(..., [], false) already carries it --
     * concatenating the full app.url here would double it up on a
     * subdirectory install. Returns null when app.url has no usable
     * scheme/host (e.g. unset, the pre-install default), so callers can fall
     * back to the request-based absolute route instead of building a URL
     * that is missing a host entirely.
     */
    public static function rootUrl($appUrl = null)
    {
        $appUrl = $appUrl !== null ? $appUrl : (string) config('app.url');

        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $host   = parse_url($appUrl, PHP_URL_HOST);

        if (!$scheme || !$host) {
            return null;
        }

        $port = parse_url($appUrl, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '');
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
        $this->account->webhook_url        = self::truncateUrl($url);
        $this->account->webhook_secret     = $secret;
        // webhook_active is tri-state: null means "not known". A response
        // that omits `active` (e.g. an empty body from a 204) tells us
        // nothing about the current state -- optimistically writing true
        // would claim knowledge we don't have.
        $this->account->webhook_active             = isset($webhook['active']) ? (bool) $webhook['active'] : null;
        $this->account->webhook_checked_at         = now();
        $this->account->webhook_check_attempted_at = now();
        $this->account->webhook_error              = null;
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

            $this->markWebhookGone();

            return null;
        }

        $active = isset($webhook['active']) ? (bool) $webhook['active'] : null;

        $this->account->webhook_active             = $active;
        $this->account->webhook_url                = isset($webhook['url']) ? self::truncateUrl($webhook['url']) : $this->account->webhook_url;
        $this->account->webhook_checked_at         = now();
        $this->account->webhook_check_attempted_at = now();
        $this->account->webhook_error              = $active === false ? $this->pauseReason() : null;
        $this->account->save();

        return $webhook;
    }

    /**
     * Reactivates our webhook. Returns null in the same "gone" case refresh()
     * does -- Kapso answering 404 to the PATCH means there is nothing left to
     * re-enable, not that this account's phone number ID or API key is wrong,
     * which is what the generic error mapper would otherwise say on the one
     * button this feature exists to provide.
     */
    public function resume()
    {
        if (!$this->account->webhook_id) {
            throw new KapsoApiException(__('This account has no registered webhook yet. Register it first.'));
        }

        try {
            // Only the active flag: re-sending secret_key or events here
            // would rewrite settings the admin may have no reason to expect
            // to change.
            $webhook = $this->client->updatePhoneNumberWebhook($this->account->webhook_id, ['active' => true]);
        } catch (KapsoApiException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }

            $this->markWebhookGone();

            return null;
        }

        // Same rule as register(): a response that omits `active` (e.g. an
        // empty body from a 204) tells us nothing about the current state --
        // writing true here would claim knowledge we don't have, even though
        // we just asked Kapso to set it.
        $this->account->webhook_active             = isset($webhook['active']) ? (bool) $webhook['active'] : null;
        $this->account->webhook_checked_at         = now();
        $this->account->webhook_check_attempted_at = now();
        $this->account->webhook_error              = null;
        $this->account->save();

        return $webhook;
    }

    /**
     * Shared by refresh() and resume(): either one hitting a 404 against our
     * stored webhook id means the same thing -- Kapso no longer has it,
     * deleted from their dashboard or the project/number it belonged to was
     * removed. There is nothing left to reconcile against, so the only
     * correct move is to forget it locally and say so, the same diagnosis
     * whichever action ran into the 404. This still counts as a successful
     * check: it stamps webhook_checked_at because we genuinely learned the
     * current state, unlike a failed call that tells us nothing.
     */
    protected function markWebhookGone()
    {
        $this->account->webhook_id                 = null;
        $this->account->webhook_active             = null;
        $this->account->webhook_checked_at         = now();
        $this->account->webhook_check_attempted_at = now();
        $this->account->webhook_error              = __('The webhook this module registered no longer exists in Kapso. Register it again.');
        $this->account->save();
    }

    /**
     * Kapso's PATCH/GET responses echo the URL back and this is written
     * verbatim so drift is visible -- but the column is string(255), and a
     * value this module did not choose (an echo, a redirect-expanded host)
     * could exceed that under strict SQL mode. Truncate rather than let
     * save() throw.
     */
    protected static function truncateUrl($url)
    {
        return $url === null ? null : mb_substr((string) $url, 0, 255);
    }

    /**
     * Kapso does not say why it paused a webhook, so ask the delivery log.
     * response_status is the HTTP code this install returned, which is the
     * one fact that distinguishes "our endpoint is rejecting deliveries" from
     * "Kapso could not reach us at all".
     */
    protected function pauseReason()
    {
        $limit = 20;

        try {
            $failures = $this->client->listWebhookDeliveries($this->account->webhook_id, '24h', $limit);
        } catch (KapsoApiException $e) {
            $failures = [];
        }

        if (!$failures) {
            return __('Kapso has paused this webhook. Kapso pauses a webhook automatically after a run of failed deliveries and never resumes it on its own.');
        }

        // GET /webhook_deliveries is documented to return "webhook delivery
        // attempts for your project, most recent first" -- so the first
        // element is the latest attempt without needing to sort here.
        $latest   = reset($failures);
        $response = (isset($latest['response_status']) && $latest['response_status'])
            ? __('HTTP :status', ['status' => (int) $latest['response_status']])
            : __('no response');

        $count = count($failures);

        // The lookup is capped at $limit, so a count that hits the cap is not
        // necessarily the true total -- say so rather than reporting a number
        // that may be too low with nothing to indicate it was truncated.
        $countText = $count >= $limit
            ? __('at least :count', ['count' => $count])
            : (string) $count;

        return __('Kapso has paused this webhook after failed deliveries. :count failed in the last 24 hours; the most recent attempt got :response from this FreeScout.', [
            'count'    => $countText,
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

        // parse_url() returns IPv6 hosts with their brackets still attached
        // (e.g. "[::1]"), which filter_var() does not accept.
        if (substr($host, 0, 1) === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }

        // IP literals are classified on their own terms -- checked before the
        // dotless-hostname rule below, which would otherwise misclassify
        // every IPv6 literal (none contain a ".") as an unreachable hostname.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if ($host === 'localhost' || substr($host, -6) === '.local' || strpos($host, '.') === false) {
            return true;
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
            if (!is_array($webhook) || !isset($webhook['url']) || $webhook['url'] !== $url || empty($webhook['id'])) {
                continue;
            }

            // A URL match alone is not proof of ownership: every account in
            // this install advertises the same webhook endpoint, so only
            // Kapso's phone-number scoping normally keeps two accounts'
            // webhooks apart. Belt and braces -- when the entry states which
            // phone number it belongs to, it must be this account's before
            // being adopted. Tolerate the field being absent.
            if (array_key_exists('phone_number_id', $webhook)
                && $webhook['phone_number_id'] !== null
                && (string) $webhook['phone_number_id'] !== (string) $this->account->phone_number_id) {
                continue;
            }

            return $webhook;
        }

        return null;
    }
}

<?php

namespace Modules\KapsoWhatsApp\Entities;

use Illuminate\Database\Eloquent\Model;

class KapsoAccount extends Model
{
    const CHANNEL      = 102;
    const CHANNEL_NAME = 'WhatsApp';

    protected $table = 'kapso_whatsapp_accounts';

    protected $fillable = [
        'name', 'phone_number_id', 'business_account_id', 'mailbox_id', 'is_active',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'webhook_active' => 'boolean',
    ];

    protected $dates = ['last_webhook_at', 'webhook_checked_at', 'webhook_check_attempted_at'];

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }

    public static function findByPhoneNumberId($phoneNumberId)
    {
        if (!$phoneNumberId) {
            return null;
        }

        return self::where('phone_number_id', (string) $phoneNumberId)
            ->where('is_active', true)
            ->first();
    }

    public function isWebhookRegistered()
    {
        return (bool) $this->webhook_id;
    }

    /**
     * Kapso pauses a webhook after a run of failed deliveries and never
     * resumes it on its own. Only meaningful for a webhook we registered:
     * webhook_active is null until Kapso has actually been asked.
     */
    public function isWebhookPaused()
    {
        return $this->isWebhookRegistered() && $this->webhook_active === false;
    }

    /**
     * True when the webhook is registered but we genuinely do not know
     * whether it is active or paused. Kapso's PATCH can answer with a 204 No
     * Content -- register() and resume() both leave webhook_active null
     * rather than guess in that case, because writing true would claim
     * knowledge we don't have. Distinct from isWebhookPaused(): that is a
     * confirmed "no", this is "we were never told".
     */
    public function isWebhookStatusUnknown()
    {
        return $this->isWebhookRegistered() && is_null($this->webhook_active);
    }

    /**
     * True when the webhook was registered against a different URL than this
     * install now advertises -- an APP_URL edit, a new domain, or an admin
     * who registered while browsing FreeScout on a second hostname.
     *
     * Compared after normalising, not with a raw !==: refresh() writes back
     * whatever URL Kapso echoes, and Kapso's own normalisation (a trailing
     * slash added, host lowercased, an explicit default port spelled out)
     * must not read as "moved" -- that would make this notice permanent and
     * un-clearable on every page load. This is purely a display comparison;
     * it never decides which webhook is ours (findOwnWebhook() alone does
     * that, with an exact match).
     */
    public function webhookUrlHasMoved($currentUrl)
    {
        return $this->isWebhookRegistered()
            && $this->webhook_url
            && $this->normalizeWebhookUrlForComparison($this->webhook_url) !== $this->normalizeWebhookUrlForComparison($currentUrl);
    }

    /**
     * Lowercases scheme and host and drops a default port (:80 for http,
     * :443 for https) so cosmetic differences do not make webhookUrlHasMoved()
     * fire forever with no way to clear it. Tolerates one trailing slash the
     * way a browser would. The path is deliberately left case-sensitive: a
     * differently-cased path is a genuinely different endpoint, not a
     * cosmetic variant.
     */
    protected function normalizeWebhookUrlForComparison($url)
    {
        $parts = parse_url((string) $url);

        if (!is_array($parts) || empty($parts['host'])) {
            return (string) $url;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host   = strtolower($parts['host']);
        $port   = $parts['port'] ?? null;

        $defaultPorts = ['http' => 80, 'https' => 443];
        if ($port !== null && isset($defaultPorts[$scheme]) && (int) $port === $defaultPorts[$scheme]) {
            $port = null;
        }

        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        $normalized = ($scheme !== '' ? $scheme.'://' : '').$host;

        if ($port !== null) {
            $normalized .= ':'.$port;
        }

        $normalized .= $path;

        if (isset($parts['query'])) {
            $normalized .= '?'.$parts['query'];
        }

        return $normalized;
    }

    public function setApiKeyAttribute($value)
    {
        $this->attributes['api_key'] = $value === null ? null : encrypt($value);
    }

    public function getApiKeyAttribute($value)
    {
        return $this->decryptOrNull($value);
    }

    public function setWebhookSecretAttribute($value)
    {
        $this->attributes['webhook_secret'] = $value === null ? null : encrypt($value);
    }

    public function getWebhookSecretAttribute($value)
    {
        return $this->decryptOrNull($value);
    }

    /**
     * Secrets written before a key rotation cannot be decrypted. Return null
     * rather than throwing, so one bad row does not 500 the webhook endpoint
     * for every account.
     */
    protected function decryptOrNull($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Exception $e) {
            \Log::error('[KapsoWhatsApp] Could not decrypt secret for account '.$this->id);

            return null;
        }
    }
}

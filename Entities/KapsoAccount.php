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
        'is_active' => 'boolean',
    ];

    protected $dates = ['last_webhook_at'];

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

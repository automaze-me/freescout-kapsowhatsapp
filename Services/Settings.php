<?php

namespace Modules\KapsoWhatsApp\Services;

use App\Option;

/**
 * The module's Kapso credentials.
 *
 * The API key lives here rather than on each account because Kapso scopes a
 * key to a *project* and phone numbers belong to that project: one key, many
 * numbers. Storing it per account duplicated the same secret and forced the
 * admin to re-enter it for every number.
 */
class Settings
{
    const API_KEY_OPTION = 'kapsowhatsapp.api_key';

    public static function apiKey()
    {
        $stored = Option::get(self::API_KEY_OPTION, '');

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return decrypt($stored);
        } catch (\Exception $e) {
            \Log::error('[KapsoWhatsApp] Could not decrypt the stored Kapso API key.');

            return null;
        }
    }

    public static function setApiKey($key)
    {
        Option::set(self::API_KEY_OPTION, ($key === null || $key === '') ? '' : encrypt($key));

        // Option::set() deliberately does not touch Option::$cache, so a value
        // already read in this process would otherwise keep being returned --
        // including on the very next line of the request that just saved it.
        unset(Option::$cache[self::API_KEY_OPTION]);
    }

    public static function hasApiKey()
    {
        return self::apiKey() !== null;
    }
}

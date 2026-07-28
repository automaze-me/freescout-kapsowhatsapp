<?php

namespace Modules\KapsoWhatsApp\Services;

class PhoneNumber
{
    /**
     * Default country code used when a number is written in national format
     * (a single leading trunk zero) without an explicit country code.
     */
    const DEFAULT_COUNTRY_CODE = '49';

    /**
     * Normalise to E.164 ("+" followed by digits).
     *
     * Deliberately dependency-free: WhatsApp always delivers `message.from` as
     * bare international digits, so full libphonenumber parsing would be
     * carrying a large dependency for one edge case (numbers typed by agents
     * in national format).
     */
    public static function toE164($raw, $defaultCountryCode = self::DEFAULT_COUNTRY_CODE)
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim((string) $raw);

        if ($trimmed === '') {
            return null;
        }

        $hadPlus = strpos($trimmed, '+') === 0;
        $digits  = preg_replace('/\D+/', '', $trimmed);

        if ($digits === '') {
            return null;
        }

        if (!$hadPlus) {
            if (strpos($digits, '00') === 0) {
                // International access prefix ("00"): strip exactly those two
                // digits. What follows already carries its own country code,
                // so no default country code is prepended.
                $digits = substr($digits, 2);
            } elseif (strpos($digits, '0') === 0) {
                // National trunk prefix: strip exactly the one leading zero
                // and prepend the default country code.
                $digits = $defaultCountryCode.substr($digits, 1);
            }
        }

        // E.164 allows at most 15 digits; anything under 8 is not a routable
        // international number and is far more likely to be junk.
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }
}

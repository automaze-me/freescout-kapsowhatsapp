<?php

namespace Modules\KapsoWhatsApp\Services;

class PhoneNumber
{
    /**
     * Normalise to E.164 ("+" followed by digits).
     *
     * Deliberately dependency-free: WhatsApp always delivers `message.from` as
     * bare international digits, so full libphonenumber parsing would be
     * carrying a large dependency for one edge case (numbers typed by agents
     * in national format).
     */
    public static function toE164($raw, $defaultCountryCode = '49')
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

        // National format: a single leading zero stands in for the country code.
        if (!$hadPlus && strpos($digits, '0') === 0) {
            $digits = $defaultCountryCode.ltrim($digits, '0');
        }

        // E.164 allows at most 15 digits; anything under 8 is not a routable
        // international number and is far more likely to be junk.
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }
}

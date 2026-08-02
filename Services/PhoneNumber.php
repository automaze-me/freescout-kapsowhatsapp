<?php

namespace Modules\KapsoWhatsApp\Services;

class PhoneNumber
{
    /**
     * Fallback default country code used when a number is written in
     * national format (a single leading trunk zero) without an explicit
     * country code, and no more specific code is available (see
     * configuredDefaultCountryCode()). Empty on purpose: no configuration is
     * specific to one deployment (see the design spec's Goals). WhatsApp
     * itself always delivers `message.from`/`message.to` as bare
     * international digits, so this only ever matters for phone numbers
     * typed locally into FreeScout in national format — and guessing a
     * country here is worse than declining to guess, since a wrong guess
     * silently produces a bogus E.164 number instead of failing safe.
     */
    const DEFAULT_COUNTRY_CODE = '';

    /**
     * The country code this installation uses for national-format numbers,
     * from the `kapsowhatsapp.default_country_code` option -- set on the
     * WhatsApp Accounts admin page (KapsoWhatsAppController::
     * saveDefaultCountryCode() normalises the input and stores bare digits,
     * no "+"). Falls back to DEFAULT_COUNTRY_CODE (empty) when
     * unset, matching the fail-safe default above.
     */
    public static function configuredDefaultCountryCode(): string
    {
        return (string) \Option::get('kapsowhatsapp.default_country_code', self::DEFAULT_COUNTRY_CODE);
    }

    /**
     * Normalise to E.164 ("+" followed by digits).
     *
     * Deliberately dependency-free: WhatsApp always delivers `message.from` as
     * bare international digits, so full libphonenumber parsing would be
     * carrying a large dependency for one edge case (numbers typed by agents
     * in national format). Deliberately does not read config/Option itself
     * either — callers that need this installation's configured country code
     * pass configuredDefaultCountryCode() explicitly, keeping this a pure,
     * unit-testable function.
     */
    public static function toE164(?string $raw, string $defaultCountryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

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
                if ($defaultCountryCode === '') {
                    // National trunk prefix, but no country code is known for
                    // this installation: a bare national number cannot be
                    // normalised to E.164 without guessing which country it
                    // belongs to, and a wrong guess would silently merge or
                    // miss customers. Decline rather than fabricate a bogus
                    // number.
                    return null;
                }

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

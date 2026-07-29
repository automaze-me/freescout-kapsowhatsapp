<?php

namespace Modules\KapsoWhatsApp\Services;

/**
 * Pure helpers over one record from GET /whatsapp/phone_numbers.
 *
 * Every human-readable field on that record is nullable in Kapso's schema, so
 * building the label an admin picks from is its own small problem: it has to
 * stay recognisable when Meta has told Kapso nothing yet.
 */
class KapsoNumber
{
    public static function label(array $record)
    {
        $number = self::text($record, 'display_phone_number');

        if ($number === '') {
            // No number Meta/Kapso has confirmed yet: the Kapso-assigned id
            // is the only thing guaranteed to distinguish this row from any
            // other, so it takes the number's place rather than being
            // dropped.
            $number = self::text($record, 'phone_number_id');
        }

        $name = self::text($record, 'verified_name');

        if ($name === '') {
            $name = self::text($record, 'name');
        }

        // An explicit '' check -- not array_filter()'s default callback,
        // which treats the string '0' as falsy and would silently drop a
        // legitimate id/name of "0".
        $parts = array_filter([$number, $name], function ($part) {
            return $part !== '';
        });

        $label = $parts
            ? implode(' — ', $parts)
            // Nothing at all is usable: no display number, no id, no name.
            // A blank option is indistinguishable from any other blank
            // option, so this has to read as something an admin could
            // actually report to support.
            : __('Unidentified WhatsApp number');

        // Only a rating that should worry someone is worth the space; GREEN is
        // the expected state and saying so on every row is noise.
        $rating = strtoupper(self::text($record, 'quality_rating'));

        if ($rating !== '' && $rating !== 'GREEN') {
            $label .= ' ('.$rating.')';
        }

        return $label;
    }

    public static function find(array $records, $phoneNumberId)
    {
        if ($phoneNumberId === null || $phoneNumberId === '' || !is_scalar($phoneNumberId)) {
            return null;
        }

        foreach ($records as $record) {
            if (is_array($record)
                && isset($record['phone_number_id'])
                && is_scalar($record['phone_number_id'])
                && (string) $record['phone_number_id'] === (string) $phoneNumberId) {
                return $record;
            }
        }

        return null;
    }

    protected static function text(array $record, $key)
    {
        return (isset($record[$key]) && is_scalar($record[$key])) ? trim((string) $record[$key]) : '';
    }
}

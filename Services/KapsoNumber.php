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
        $name   = self::text($record, 'verified_name') ?: self::text($record, 'name');

        $parts = array_filter([$number, $name]);

        if (!$parts) {
            $parts = [self::text($record, 'phone_number_id')];
        }

        $label = implode(' — ', array_filter($parts));

        // Only a rating that should worry someone is worth the space; GREEN is
        // the expected state and saying so on every row is noise.
        $rating = strtoupper((string) self::text($record, 'quality_rating'));

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

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
    /**
     * Just the human name Kapso/Meta has for this number -- Meta's own
     * verified_name, falling back to the editable name Kapso stores -- with
     * no phone number and no quality rating mixed in. label() needs both of
     * those to tell rows apart in a dropdown; a fallback for the account's
     * own free-text Name field does not, since it is naming one already-
     * chosen account rather than distinguishing it from others. '' when
     * neither field is set.
     */
    public static function humanName(array $record)
    {
        $name = self::text($record, 'verified_name');

        return $name !== '' ? $name : self::text($record, 'name');
    }

    /**
     * The record's human-readable number alone ("+49 151 1"), '' when Meta
     * hasn't confirmed one yet -- NO id fallback, unlike displayNumber():
     * this is what gets STORED on KapsoAccount::phone_number, and storing
     * the id there would defeat the column's whole purpose (the entity's
     * own display_number accessor already falls back to the id at read
     * time).
     */
    public static function phoneNumber(array $record)
    {
        return self::text($record, 'display_phone_number');
    }

    /**
     * The number's own display string -- what Meta shows for it, e.g.
     * "+49 151 1" -- falling back to the Kapso-assigned id when Meta hasn't
     * confirmed a number yet. Stable: unlike label(), never mixes in a
     * quality rating, so it is safe to use as a last resort for a *stored*
     * value (an account's name) rather than only a dropdown row that is
     * rebuilt fresh from Kapso on every page load.
     */
    public static function displayNumber(array $record)
    {
        $number = self::phoneNumber($record);

        // No number Meta/Kapso has confirmed yet: the Kapso-assigned id is
        // the only thing guaranteed to distinguish this row from any other,
        // so it takes the number's place rather than being dropped.
        return $number !== '' ? $number : self::text($record, 'phone_number_id');
    }

    public static function label(array $record)
    {
        $number = self::displayNumber($record);

        $name = self::humanName($record);

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

<?php

namespace Modules\KapsoWhatsApp\Tests\Unit;

use Modules\KapsoWhatsApp\Services\KapsoNumber;
use Modules\KapsoWhatsApp\Tests\TestCase;

class KapsoNumberTest extends TestCase
{
    public function test_a_full_record_reads_as_number_then_business_name()
    {
        $label = KapsoNumber::label([
            'phone_number_id'      => '1234567890',
            'display_phone_number' => '+49 151 2345 6789',
            'verified_name'        => 'Acme GmbH',
            'quality_rating'       => 'GREEN',
        ]);

        $this->assertStringContainsString('+49 151 2345 6789', $label);
        $this->assertStringContainsString('Acme GmbH', $label);
    }

    /**
     * Every human-readable field is nullable in Kapso's schema. The label must
     * degrade rather than render an empty option the admin cannot tell apart
     * from any other.
     */
    public function test_a_record_with_no_display_number_falls_back_to_the_kapso_label()
    {
        $label = KapsoNumber::label([
            'phone_number_id' => '1234567890',
            'name'            => 'Support line',
        ]);

        $this->assertStringContainsString('Support line', $label);
    }

    public function test_a_record_with_nothing_readable_falls_back_to_the_id()
    {
        $this->assertStringContainsString('1234567890', KapsoNumber::label(['phone_number_id' => '1234567890']));
    }

    public function test_a_poor_quality_rating_is_surfaced_and_a_green_one_is_not()
    {
        $base = ['phone_number_id' => '1', 'display_phone_number' => '+49 1', 'verified_name' => 'A'];

        $this->assertStringContainsString('RED', KapsoNumber::label($base + ['quality_rating' => 'RED']));
        $this->assertStringNotContainsString('GREEN', KapsoNumber::label($base + ['quality_rating' => 'GREEN']));
    }

    public function test_find_matches_on_the_phone_number_id_as_a_string()
    {
        $records = [
            ['phone_number_id' => '111'],
            ['phone_number_id' => '222', 'verified_name' => 'Wanted'],
        ];

        $this->assertSame('Wanted', KapsoNumber::find($records, '222')['verified_name']);
        $this->assertSame('Wanted', KapsoNumber::find($records, 222)['verified_name'], 'a numeric id must still match');
        $this->assertNull(KapsoNumber::find($records, '333'));
        $this->assertNull(KapsoNumber::find($records, ''));
        $this->assertNull(KapsoNumber::find($records, null));
    }

    public function test_find_ignores_malformed_entries()
    {
        $this->assertNull(KapsoNumber::find([['no_id' => true], 'not-an-array'], '111'));
    }

    /**
     * array_filter()'s default callback treats '' as the only thing to keep
     * out, but a naive implementation reaching for the default callback would
     * treat '0' as falsy too and silently drop it -- and a completely empty
     * record must still read as something rather than a blank line.
     */
    public function test_a_completely_empty_record_never_produces_a_blank_label()
    {
        $label = KapsoNumber::label([]);

        $this->assertNotSame('', trim($label));
        $this->assertStringContainsString('Unidentified', $label);
    }

    public function test_a_zero_string_id_is_not_dropped_as_falsy()
    {
        $label = KapsoNumber::label(['phone_number_id' => '0']);

        $this->assertNotSame('', trim($label));
        $this->assertSame('0', $label);
    }

    public function test_a_zero_string_verified_name_is_not_dropped_as_falsy()
    {
        $label = KapsoNumber::label(['phone_number_id' => '123', 'verified_name' => '0']);

        $this->assertNotSame('', trim($label));
        $this->assertSame('123 — 0', $label);
    }

    public function test_whitespace_only_field_values_are_treated_as_absent_not_used_verbatim()
    {
        $label = KapsoNumber::label([
            'phone_number_id'      => '1234567890',
            'display_phone_number' => '   ',
            'verified_name'        => "\t\n",
        ]);

        $this->assertNotSame('', trim($label));
        $this->assertStringContainsString('1234567890', $label);
        // No dangling separator left behind by a field that trimmed to nothing.
        $this->assertStringNotContainsString('—  —', $label);
        $this->assertStringNotContainsString(' — ', $label);
    }

    public function test_a_non_scalar_field_value_is_ignored_rather_than_used_or_fatal()
    {
        $label = KapsoNumber::label([
            'phone_number_id'      => '1234567890',
            'display_phone_number' => ['nested' => 'array'],
            'verified_name'        => new \stdClass(),
        ]);

        $this->assertNotSame('', trim($label));
        $this->assertStringContainsString('1234567890', $label);
    }

    public function test_quality_rating_is_compared_case_insensitively()
    {
        $label = KapsoNumber::label(['phone_number_id' => '1', 'quality_rating' => 'red']);

        $this->assertNotSame('', trim($label));
        $this->assertStringContainsString('RED', $label);
    }

    /**
     * displayNumber() is the stable last-resort value applyCreateRequest()
     * names an account with -- unlike label(), it must never carry a quality
     * rating, which is a moment-in-time signal that would otherwise get
     * baked into a stored name.
     */
    public function test_display_number_never_includes_a_quality_rating()
    {
        $number = KapsoNumber::displayNumber([
            'phone_number_id'      => '1',
            'display_phone_number' => '+49 151 1',
            'quality_rating'       => 'RED',
        ]);

        $this->assertSame('+49 151 1', $number);
    }

    public function test_display_number_falls_back_to_the_phone_number_id()
    {
        $this->assertSame('1234567890', KapsoNumber::displayNumber(['phone_number_id' => '1234567890']));
    }
}

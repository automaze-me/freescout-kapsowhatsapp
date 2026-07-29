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
}

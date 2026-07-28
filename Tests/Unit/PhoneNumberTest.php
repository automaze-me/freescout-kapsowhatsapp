<?php

namespace Modules\KapsoWhatsApp\Tests\Unit;

use Modules\KapsoWhatsApp\Services\PhoneNumber;
use Modules\KapsoWhatsApp\Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_bare_international_digits_gain_a_plus()
    {
        $this->assertSame('+4915112345678', PhoneNumber::toE164('4915112345678'));
    }

    public function test_existing_plus_is_preserved()
    {
        $this->assertSame('+4915112345678', PhoneNumber::toE164('+4915112345678'));
    }

    public function test_formatting_characters_are_stripped()
    {
        $this->assertSame('+4915112345678', PhoneNumber::toE164('+49 (151) 123-456 78'));
    }

    public function test_national_leading_zero_is_replaced_with_default_country_code()
    {
        $this->assertSame('+4915112345678', PhoneNumber::toE164('015112345678', '49'));
    }

    public function test_international_access_prefix_00_is_replaced_with_a_plus()
    {
        // "0030 1234567" is a Greek number written with the "00"
        // international access prefix followed by its own country code
        // (30). Only the "00" is stripped; the country code that follows
        // must be preserved as-is, not treated as a national number and
        // re-prefixed with the default country code.
        $this->assertSame('+301234567', PhoneNumber::toE164('0030 1234567', '49'));
    }

    public function test_single_leading_zero_is_a_national_trunk_prefix_not_an_access_prefix()
    {
        // "030 1234567" is a German (Berlin) number with a single national
        // trunk zero. Only that one zero is stripped, then the default
        // country code is prepended.
        $this->assertSame('+49301234567', PhoneNumber::toE164('030 1234567', '49'));
    }

    public function test_international_access_prefix_and_national_trunk_prefix_do_not_collide()
    {
        // These are two different real numbers in different countries.
        // Collapsing them to the same E.164 value would let one customer's
        // WhatsApp identity match another's stored number.
        $greek  = PhoneNumber::toE164('0030 1234567', '49');
        $german = PhoneNumber::toE164('030 1234567', '49');

        $this->assertNotSame($greek, $german);
        $this->assertSame('+301234567', $greek);
        $this->assertSame('+49301234567', $german);
    }

    public function test_empty_and_junk_input_returns_null()
    {
        $this->assertNull(PhoneNumber::toE164(null));
        $this->assertNull(PhoneNumber::toE164(''));
        $this->assertNull(PhoneNumber::toE164('abc'));
        $this->assertNull(PhoneNumber::toE164('12345'), 'too short to be a valid international number');
    }
}

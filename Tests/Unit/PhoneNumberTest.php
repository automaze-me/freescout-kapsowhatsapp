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

    public function test_empty_and_junk_input_returns_null()
    {
        $this->assertNull(PhoneNumber::toE164(null));
        $this->assertNull(PhoneNumber::toE164(''));
        $this->assertNull(PhoneNumber::toE164('abc'));
        $this->assertNull(PhoneNumber::toE164('12345'), 'too short to be a valid international number');
    }
}

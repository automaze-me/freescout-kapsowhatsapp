<?php

namespace Modules\KapsoWhatsApp\Tests\Unit;

use App\Option;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

class SettingsTest extends TestCase
{
    public function test_the_api_key_round_trips()
    {
        Settings::setApiKey('kapso-key-abc');

        $this->assertSame('kapso-key-abc', Settings::apiKey());
        $this->assertTrue(Settings::hasApiKey());
    }

    public function test_no_key_reads_as_null_not_empty_string()
    {
        $this->assertNull(Settings::apiKey());
        $this->assertFalse(Settings::hasApiKey());
    }

    public function test_the_key_is_encrypted_at_rest()
    {
        Settings::setApiKey('kapso-key-abc');

        $raw = Option::where('name', Settings::API_KEY_OPTION)->value('value');

        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString('kapso-key-abc', $raw, 'the API key must never be stored in plaintext');
    }

    /**
     * Option::get() populates Option::$cache and Option::set() does not clear
     * it, so without explicit invalidation a read taken before a write keeps
     * returning the old value for the rest of the process -- which in a web
     * request means the admin saves a new key and the very next call still
     * uses the old one.
     */
    public function test_a_read_before_a_write_does_not_poison_the_next_read()
    {
        Settings::setApiKey('first-key');
        $this->assertSame('first-key', Settings::apiKey());

        Settings::setApiKey('second-key');
        $this->assertSame('second-key', Settings::apiKey());
    }

    public function test_clearing_the_key_removes_it()
    {
        Settings::setApiKey('kapso-key-abc');
        Settings::setApiKey(null);

        $this->assertNull(Settings::apiKey());
        $this->assertFalse(Settings::hasApiKey());
    }

    /**
     * A value written before an APP_KEY rotation cannot be decrypted. Return
     * null rather than throwing, so one bad option does not 500 every admin
     * page in the module.
     */
    public function test_an_undecryptable_value_reads_as_null()
    {
        Option::set(Settings::API_KEY_OPTION, 'not-a-valid-ciphertext');
        Option::$cache = [];

        $this->assertNull(Settings::apiKey());
    }
}

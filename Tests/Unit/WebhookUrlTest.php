<?php

namespace Modules\KapsoWhatsApp\Tests\Unit;

use Modules\KapsoWhatsApp\Services\WebhookRegistrar;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * Kapso delivers from the public internet. An install that advertises
 * localhost or a private address can register a webhook perfectly happily and
 * then never receive a single delivery -- which looks like a broken module.
 * This is what lets the settings page say so instead.
 */
class WebhookUrlTest extends TestCase
{
    public function test_public_hostnames_are_treated_as_reachable()
    {
        $this->assertFalse(WebhookRegistrar::looksUnreachable('https://help.example.com/kapso-whatsapp/webhook'));
        $this->assertFalse(WebhookRegistrar::looksUnreachable('http://help.example.com/kapso-whatsapp/webhook'));
        $this->assertFalse(WebhookRegistrar::looksUnreachable('https://8.8.8.8/kapso-whatsapp/webhook'));
    }

    public function test_local_and_private_addresses_are_flagged()
    {
        foreach ([
            'http://localhost:8090/kapso-whatsapp/webhook',
            'http://127.0.0.1/kapso-whatsapp/webhook',
            'http://192.168.1.10/kapso-whatsapp/webhook',
            'http://10.0.0.5/kapso-whatsapp/webhook',
            'http://helpdesk.local/kapso-whatsapp/webhook',
            'http://helpdesk/kapso-whatsapp/webhook',
            'not-a-url',
        ] as $url) {
            $this->assertTrue(WebhookRegistrar::looksUnreachable($url), $url.' should be flagged as unreachable');
        }
    }
}

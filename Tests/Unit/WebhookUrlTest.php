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

    /**
     * parse_url() returns IPv6 hosts with their brackets still attached
     * (e.g. "[2606:4700:4700::1111]"), and IPv6 literals never contain a
     * "." -- so a naive dotless-hostname check would misclassify every one
     * of them, public or not, as unreachable.
     */
    public function test_a_public_ipv6_literal_is_treated_as_reachable()
    {
        $this->assertFalse(WebhookRegistrar::looksUnreachable('http://[2606:4700:4700::1111]/kapso-whatsapp/webhook'));
    }

    public function test_ipv6_loopback_and_unique_local_addresses_are_flagged()
    {
        foreach ([
            'http://[::1]/kapso-whatsapp/webhook',
            'http://[fd12:3456:789a:1::1]/kapso-whatsapp/webhook',
        ] as $url) {
            $this->assertTrue(WebhookRegistrar::looksUnreachable($url), $url.' should be flagged as unreachable');
        }
    }

    /**
     * rootUrl() is the pure "scheme + host [+ port]" extraction that
     * webhookUrl() combines with the route's own relative path. Tested
     * directly with fabricated app.url values so the subdirectory case does
     * not depend on this process's routes actually being registered under a
     * subdirectory prefix (they are registered once, at boot, from whatever
     * APP_URL this test run's .env happens to have).
     */
    public function test_root_url_keeps_scheme_and_host_but_drops_any_path()
    {
        $this->assertSame('https://help.example.com', WebhookRegistrar::rootUrl('https://help.example.com'));
        $this->assertSame('http://help.example.com', WebhookRegistrar::rootUrl('http://help.example.com/'));

        // The subdirectory case: Helper::getSubdirectory() derives this
        // module's route prefix from app.url's own path, so the relative
        // route path returned by route(..., [], false) already carries it.
        // rootUrl() must drop the path here, or concatenating the two would
        // double the subdirectory up.
        $this->assertSame('https://help.example.com', WebhookRegistrar::rootUrl('https://help.example.com/helpdesk'));
    }

    public function test_root_url_keeps_a_non_default_port()
    {
        $this->assertSame('http://10.0.0.224:8090', WebhookRegistrar::rootUrl('http://10.0.0.224:8090'));
        $this->assertSame('https://help.example.com:8443', WebhookRegistrar::rootUrl('https://help.example.com:8443/helpdesk'));
    }

    public function test_root_url_is_null_when_app_url_has_no_usable_scheme_or_host()
    {
        $this->assertNull(WebhookRegistrar::rootUrl(''));
        $this->assertNull(WebhookRegistrar::rootUrl('not-a-url'));
        $this->assertNull(WebhookRegistrar::rootUrl('/just/a/path'));
    }

    /**
     * The bug this whole finding is about: FreeScout core calls
     * forceScheme('https') but never forceRootUrl(), so a plain
     * route('kapsowhatsapp.webhook') follows the current request's host, not
     * config('app.url'). webhookUrl() must not do that -- it must track
     * app.url even when nothing about the current request changed.
     */
    public function test_webhook_url_follows_a_change_to_app_url_rather_than_the_request()
    {
        $original = config('app.url');

        try {
            config(['app.url' => 'https://canonical.example.com']);

            $this->assertSame(
                'https://canonical.example.com'.route('kapsowhatsapp.webhook', [], false),
                WebhookRegistrar::webhookUrl()
            );

            config(['app.url' => 'https://second-canonical.example.com']);

            $this->assertSame(
                'https://second-canonical.example.com'.route('kapsowhatsapp.webhook', [], false),
                WebhookRegistrar::webhookUrl(),
                'webhookUrl() must track config(app.url), not cache the first value it saw'
            );
        } finally {
            config(['app.url' => $original]);
        }
    }

    /**
     * Before app.url is configured (fresh install, still the Laravel
     * default), rootUrl() returns null and webhookUrl() must fall back to
     * the request-based absolute route rather than emit a URL with no host.
     */
    public function test_webhook_url_falls_back_to_the_request_based_route_when_app_url_is_empty()
    {
        $original = config('app.url');

        try {
            config(['app.url' => '']);

            $this->assertSame(route('kapsowhatsapp.webhook'), WebhookRegistrar::webhookUrl());
        } finally {
            config(['app.url' => $original]);
        }
    }
}

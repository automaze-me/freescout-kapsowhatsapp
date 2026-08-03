<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use Modules\KapsoWhatsApp\Tests\TestCase;

class ChannelRegistrationTest extends TestCase
{
    public function test_channel_105_is_registered()
    {
        $channels = \Eventy::filter('channels.list', []);

        $this->assertArrayHasKey(105, $channels);
        $this->assertSame('WhatsApp', $channels[105]);
    }

    public function test_channel_name_resolves_for_105()
    {
        $this->assertSame('WhatsApp', \Eventy::filter('channel.name', '', 105));
    }

    public function test_channel_name_is_untouched_for_other_codes()
    {
        $this->assertSame('', \Eventy::filter('channel.name', '', 100));
    }
}

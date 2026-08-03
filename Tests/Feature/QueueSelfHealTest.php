<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use Modules\KapsoWhatsApp\Providers\KapsoWhatsAppServiceProvider;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * FreeScout's module install/update flow never restarts queue workers, so a
 * long-running worker keeps executing the module code it booted with — on a
 * live install that meant a stale worker kept stamping new conversations
 * with the pre-0.2.2 channel code while the web UI checked the new one.
 *
 * The self-heal compares the version stored in the options table against the
 * version in module.json ON DISK (bypassing every cache layer, so even a
 * stale-code worker notices) and broadcasts queue:restart exactly once per
 * version change.
 */
class QueueSelfHealTest extends TestCase
{
    private const OPTION = 'kapsowhatsapp.version';
    private const RESTART_KEY = 'illuminate:queue:restart';

    private function diskVersion(): string
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../../module.json'), true);

        return $manifest['version'];
    }

    private function forgetStoredVersion(): void
    {
        \App\Option::where('name', self::OPTION)->delete();
        \App\Option::$cache = [];
    }

    public function test_restarts_queue_and_stores_version_on_first_boot()
    {
        $this->forgetStoredVersion();
        \Cache::forget(self::RESTART_KEY);

        $restarted = KapsoWhatsAppServiceProvider::restartQueueIfVersionChanged();

        $this->assertTrue($restarted);
        $this->assertNotNull(\Cache::get(self::RESTART_KEY));
        $this->assertSame($this->diskVersion(), \App\Option::get(self::OPTION));
    }

    public function test_restarts_queue_when_stored_version_differs()
    {
        \App\Option::set(self::OPTION, '0.0.1');
        \Cache::forget(self::RESTART_KEY);

        $restarted = KapsoWhatsAppServiceProvider::restartQueueIfVersionChanged();

        $this->assertTrue($restarted);
        $this->assertNotNull(\Cache::get(self::RESTART_KEY));
        $this->assertSame($this->diskVersion(), \App\Option::get(self::OPTION));
    }

    public function test_does_not_restart_when_stored_version_matches()
    {
        \App\Option::set(self::OPTION, $this->diskVersion());
        \Cache::forget(self::RESTART_KEY);

        $restarted = KapsoWhatsAppServiceProvider::restartQueueIfVersionChanged();

        $this->assertFalse($restarted);
        $this->assertNull(\Cache::get(self::RESTART_KEY));
        $this->assertSame($this->diskVersion(), \App\Option::get(self::OPTION));
    }
}

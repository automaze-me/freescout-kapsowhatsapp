<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Attachment;
use Modules\KapsoWhatsApp\Tests\TestCase;

/**
 * Regression guards for a real production-data-loss incident (2026-07-30):
 * with a stale config cache, the suite ran against the LIVE mysql
 * connection; the setUp() DB guard fail()ed BEFORE the attachment
 * watermark was recorded, PHPUnit still ran tearDown(), and the cleanup's
 * `where('id', '>', 0)` matched every production attachment —
 * deleteForever() removed the real files from disk permanently while its
 * DB deletes were silently undone by the test-transaction rollback (disk
 * writes are not transactional). Files gone, rows intact.
 *
 * These tests drive the private cleanup directly via reflection: what they
 * pin is that it refuses to delete anything when (a) the watermark was
 * never recorded (setUp did not complete) or (b) the active connection is
 * not `testing` — the two conditions that held during the incident.
 */
class AttachmentCleanupSafetyTest extends TestCase
{
    protected function runCleanup(TestCase $instance): void
    {
        $method = new \ReflectionMethod(TestCase::class, 'deleteAttachmentFilesWrittenDuringThisTest');
        $method->setAccessible(true);
        $method->invoke($instance);
    }

    protected function setWatermark($value): void
    {
        $prop = new \ReflectionProperty(TestCase::class, 'attachmentIdWatermark');
        $prop->setAccessible(true);
        $prop->setValue($this, $value);
    }

    public function test_cleanup_refuses_to_run_when_the_watermark_was_never_recorded()
    {
        // A "pre-existing" attachment, as production rows were during the
        // incident: id above 0, so an unguarded `where('id', '>', 0)`
        // matches it.
        $attachment = Attachment::create('precious.jpg', 'image/jpeg', null, 'precious-bytes', null);
        $this->assertTrue($attachment->fileExists());

        // Simulate the incident: setUp never completed, watermark never
        // recorded.
        $this->setWatermark(null);

        $this->runCleanup($this);

        $this->assertTrue(
            $attachment->fileExists(),
            'cleanup must not delete anything when setUp() never recorded the watermark'
        );
        $this->assertNotNull(Attachment::find($attachment->id));

        // Restore a sane watermark so this test's own tearDown cleans up
        // `precious.jpg` normally.
        $this->setWatermark(0);
    }

    public function test_cleanup_refuses_to_run_off_the_testing_connection()
    {
        $attachment = Attachment::create('precious2.jpg', 'image/jpeg', null, 'precious-bytes-2', null);
        $this->assertTrue($attachment->fileExists());

        // Watermark recorded (0 = delete everything above id 0, as in the
        // incident), but the active default connection claims to be
        // something other than `testing` — the cleanup must trust the
        // connection check over the watermark.
        //
        // The fake name is deliberately a NONEXISTENT connection, never
        // `mysql`: if the guard under test ever regresses, the cleanup's
        // query throws (connection not configured) and this test errors —
        // instead of the first version of this test, which pointed the
        // unguarded cleanup at the real mysql connection and deleted the
        // dev install's production attachment rows while proving the bug.
        // A regression test must not carry live ammunition.
        $this->setWatermark(0);
        $originalDefault = config('database.default');
        config(['database.default' => 'kwa-nonexistent-guard-probe']);

        try {
            $this->runCleanup($this);
        } finally {
            config(['database.default' => $originalDefault]);
        }

        $this->assertTrue(
            $attachment->fileExists(),
            'cleanup must never delete files while a non-testing connection is active'
        );
        $this->assertNotNull(Attachment::find($attachment->id));
    }
}

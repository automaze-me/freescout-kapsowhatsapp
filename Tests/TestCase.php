<?php

namespace Modules\KapsoWhatsApp\Tests;

use App\Attachment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\SystemUser;
use Tests\TestCase as CoreTestCase;

abstract class TestCase extends CoreTestCase
{
    use DatabaseTransactions;

    /**
     * The highest Attachment id that already existed before this test ran.
     * See deleteAttachmentFilesWrittenDuringThisTest() below for why this
     * matters: it is what keeps that method from touching a single row it
     * did not create itself.
     */
    private $attachmentIdWatermark = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'testing') {
            $this->fail(
                'Tests must run on the "testing" DB connection but "'.config('database.default').'" is active. '
                .'Run "make artisan c=config:clear" and try again.'
            );
        }

        if (!\Module::isActive('kapsowhatsapp')) {
            $this->markTestSkipped('The kapsowhatsapp module must be active in the testing database.');
        }

        $this->attachmentIdWatermark = (int) Attachment::max('id');
    }

    /**
     * Centralised here (rather than in each test's own tearDown()) so a
     * future test that adopts the KapsoClient::fake() seam cannot forget to
     * reset it and leak a fake handler into whichever test runs next.
     * SystemUser::clearCache() belongs here for the same reason: its static
     * cache would otherwise keep pointing at a user id from a previous,
     * now-rolled-back test (verified live -- without this, the second of two
     * tests that both call SystemUser::get() gets back an id with no
     * matching row at all).
     */
    protected function tearDown(): void
    {
        KapsoClient::clearFake();
        SystemUser::clearCache();
        $this->deleteAttachmentFilesWrittenDuringThisTest();

        parent::tearDown();
    }

    /**
     * Attachment::create() writes the file to disk *and* inserts a DB row.
     * DatabaseTransactions wraps this whole test in one connection
     * transaction and rolls it back after tearDown() — which discards the DB
     * row but cannot touch the disk half, since a filesystem write is not
     * part of the SQL transaction. Without this, every test that creates a
     * real attachment (e.g. via ProcessInboundMessage's media handling)
     * leaves a stray file behind on every single run, forever. Deleting the
     * files here (via the connection that is still open — parent::tearDown()
     * is what actually rolls it back) is the only place that can see them.
     *
     * Scoped to attachmentIdWatermark: this is a PUBLIC module whose suite
     * may run against a testing database seeded from a production dump (a
     * normal practice). Attachment::deleteForever() unlinks from the storage
     * disk, so unscoped `Attachment::all()` would permanently delete every
     * production attachment file in the shared storage/app/attachment tree
     * on every test run -- the method's name would describe the intent, not
     * the behaviour. Only rows created during this test (id above the
     * watermark recorded in setUp()) are ever touched.
     */
    private function deleteAttachmentFilesWrittenDuringThisTest(): void
    {
        $attachments = Attachment::where('id', '>', $this->attachmentIdWatermark)->get();

        if ($attachments->isNotEmpty()) {
            Attachment::deleteForever($attachments);
        }
    }

    /**
     * This is a public module: its suite must pass against a freshly migrated
     * database belonging to whoever installs it, so fixtures are created via
     * factories rather than assumed to pre-exist. Note the User factory does
     * not set `role`, so it must be passed explicitly.
     */
    protected function adminUser(): \App\User
    {
        return factory(\App\User::class)->create(['role' => \App\User::ROLE_ADMIN]);
    }

    protected function regularUser(): \App\User
    {
        return factory(\App\User::class)->create(['role' => \App\User::ROLE_USER]);
    }

    protected function testMailbox(): \App\Mailbox
    {
        return factory(\App\Mailbox::class)->create();
    }
}

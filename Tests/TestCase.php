<?php

namespace Modules\KapsoWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase as CoreTestCase;

abstract class TestCase extends CoreTestCase
{
    use DatabaseTransactions;

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

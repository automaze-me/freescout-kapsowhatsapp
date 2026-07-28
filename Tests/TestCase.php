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
}

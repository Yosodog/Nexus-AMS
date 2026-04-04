<?php

namespace Tests\Integration;

use Tests\TestCase;

abstract class MySqlIntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL integration tests require the mysql connection.');
        }

        $this->artisan('migrate:fresh', ['--force' => true]);
    }
}

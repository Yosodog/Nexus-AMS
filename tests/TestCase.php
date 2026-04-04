<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function ensureIsolatedTestDatabase(?string $connection = null): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Destructive test setup may only run in the testing environment.');
        }

        $connection = $connection ?? config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $normalizedDatabase = strtolower($database);

        if ($connection === 'sqlite') {
            if ($database === ':memory:') {
                return;
            }

            if (str_contains($normalizedDatabase, 'test') || str_contains($normalizedDatabase, 'browser')) {
                return;
            }

            throw new RuntimeException('Destructive test setup refused to run on a non-isolated SQLite database.');
        }

        if (! str_contains($normalizedDatabase, 'test')) {
            throw new RuntimeException("Destructive test setup refused to run on database [{$database}].");
        }
    }
}

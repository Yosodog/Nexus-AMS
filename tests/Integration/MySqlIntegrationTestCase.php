<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class MySqlIntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL integration tests require the mysql connection.');
        }

        $this->ensureIsolatedTestDatabase('mysql');
        $politicsAndWarEndpoint = rtrim((string) config('services.pw.endpoint'), '?');
        Http::fake([
            $politicsAndWarEndpoint.'*' => Http::response([
                'data' => [
                    'game_info' => ['city_average' => 20.0],
                ],
            ]),
        ]);
        $this->artisan('migrate:fresh', ['--force' => true]);
    }
}

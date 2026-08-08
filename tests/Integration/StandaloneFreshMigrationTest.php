<?php

namespace Tests\Integration;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StandaloneFreshMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Standalone migration integration tests require the mysql connection.');
        }

        $this->ensureIsolatedTestDatabase('mysql');
        config(['nexus.runtime' => NexusRuntime::Standalone->value]);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }

    public function test_fresh_standalone_migration_requires_no_provider_state(): void
    {
        $this->artisan('migrate:fresh', ['--drop-views' => true, '--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('nations'));
        $this->assertTrue(Schema::hasTable('cities'));
        $this->assertTrue(Schema::hasTable('process_heartbeats'));
        $this->assertTrue(Schema::hasTable('scheduled_task_runs'));
        $this->assertDatabaseMissing('settings', ['key' => 'pw_city_average']);
    }
}

<?php

namespace Tests\Feature\Database;

use App\Enums\NexusRuntime;
use App\Services\RuntimeCapabilities;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyAttackRetentionBoundaryTest extends TestCase
{
    public function test_hosted_migration_does_not_try_to_alter_a_public_world_table(): void
    {
        config(['nexus.runtime' => NexusRuntime::HostedTenant->value]);
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
        $this->assertFalse(Schema::hasTable('war_attacks'));
        $migration = require database_path('migrations/2026_09_13_000059_add_is_legacy_history_to_war_attacks_table.php');
        $migration->up();
        $migration->down();
        $this->assertFalse(Schema::hasTable('war_attacks'));
    }
}

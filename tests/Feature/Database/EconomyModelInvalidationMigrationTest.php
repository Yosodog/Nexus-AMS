<?php

namespace Tests\Feature\Database;

use App\Models\NationBuildRecommendation;
use App\Models\NationProfitabilitySnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EconomyModelInvalidationMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_adds_game_date_without_estimating_legacy_rows_and_demotes_existing_v2_results(): void
    {
        $profitability = NationProfitabilitySnapshot::query()->create([
            'nation_id' => 1001,
            'model_version' => 2,
            'leader_name' => 'Leader',
            'nation_name' => 'Nation',
            'resource_profit_per_day' => [],
            'calculated_at' => now(),
        ]);
        $recommendation = NationBuildRecommendation::query()->create([
            'nation_id' => 1001,
            'model_version' => 2,
            'recommended_build_json' => [],
            'resource_profit_per_day' => [],
            'calculated_at' => now(),
        ]);
        $migration = $this->migration();

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('radiation_snapshots', 'game_date'));
            $migration->up();
        } finally {
            if (! Schema::hasColumn('radiation_snapshots', 'game_date')) {
                $migration->up();
            }
        }

        $this->assertTrue(Schema::hasColumn('radiation_snapshots', 'game_date'));
        $this->assertSame(1, $profitability->fresh()->model_version);
        $this->assertSame(1, $recommendation->fresh()->model_version);

        $profitability->update(['model_version' => 2]);
        $recommendation->update(['model_version' => 2]);
        $migration->up();

        $this->assertSame(1, $profitability->fresh()->model_version);
        $this->assertSame(1, $recommendation->fresh()->model_version);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_02_130747_add_game_date_to_radiation_snapshots_and_invalidate_economy_results.php'
        );
    }
}

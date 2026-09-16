<?php

namespace Tests\Feature\Database;

use App\Models\RecruitmentMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecruitmentABTestingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_can_roll_back_and_reapply_after_multiple_variants_exist(): void
    {
        $firstVariant = RecruitmentMessage::factory()->create(['type' => 'variant']);
        $secondVariant = RecruitmentMessage::factory()->create(['type' => 'variant']);
        $migration = $this->migration();

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('recruitment_messages', 'name'));
            $this->assertSame(
                'legacy_variant_'.$firstVariant->id,
                DB::table('recruitment_messages')->where('id', $firstVariant->id)->value('type')
            );
            $this->assertSame(
                'legacy_variant_'.$secondVariant->id,
                DB::table('recruitment_messages')->where('id', $secondVariant->id)->value('type')
            );
        } finally {
            $migration->up();
        }

        $this->assertDatabaseHas('recruitment_messages', [
            'id' => $firstVariant->id,
            'type' => 'variant',
            'name' => 'Recovered Variant '.$firstVariant->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('recruitment_messages', [
            'id' => $secondVariant->id,
            'type' => 'variant',
            'name' => 'Recovered Variant '.$secondVariant->id,
            'is_active' => false,
        ]);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_09_15_000001_add_ab_testing_to_recruitment_system.php'
        );
    }
}

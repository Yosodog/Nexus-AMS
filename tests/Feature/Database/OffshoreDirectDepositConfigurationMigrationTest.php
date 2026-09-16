<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OffshoreDirectDepositConfigurationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_alliance_ids_are_migrated_as_null(): void
    {
        $migration = $this->migration();
        $migration->down();

        try {
            $now = now();

            DB::table('settings')->insert([
                [
                    'key' => 'dd_tax_id',
                    'value' => '13670',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'key' => 'dd_fallback_tax_id',
                    'value' => '78',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            DB::table('nations')->insert([
                'id' => 900001,
                'alliance_id' => 0,
                'alliance_position' => 'NOALLIANCE',
                'alliance_position_id' => 0,
                'nation_name' => 'Migration Test Nation',
                'leader_name' => 'Migration Test Leader',
                'continent' => 'NA',
                'war_policy' => 'ATTRITION',
                'war_policy_turns' => 0,
                'domestic_policy' => 'MANIFEST_DESTINY',
                'domestic_policy_turns' => 0,
                'color' => 'blue',
                'num_cities' => 0,
                'score' => 0,
                'update_tz' => 0,
                'population' => 0,
                'flag' => null,
                'vacation_mode_turns' => 0,
                'beige_turns' => 0,
                'espionage_available' => false,
                'discord' => null,
                'discord_id' => null,
                'turns_since_last_city' => 0,
                'turns_since_last_project' => 0,
                'projects' => 0,
                'project_bits' => '0',
                'wars_won' => 0,
                'wars_lost' => 0,
                'tax_id' => null,
                'alliance_seniority' => 0,
                'gross_national_income' => 0,
                'gross_domestic_product' => 0,
                'vip' => false,
                'commendations' => 0,
                'denouncements' => 0,
                'offensive_wars_count' => 0,
                'defensive_wars_count' => 0,
                'money_looted' => 0,
                'total_infrastructure_destroyed' => 0,
                'total_infrastructure_lost' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('direct_deposit_enrollments')->insert([
                'nation_id' => 900001,
                'account_id' => 1,
                'previous_tax_id' => 300,
                'enrolled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $migration->up();
            $migration->up();

            $enrollment = DB::table('direct_deposit_enrollments')
                ->where('nation_id', 900001)
                ->first();

            $this->assertNull($enrollment->alliance_id);
            $this->assertNull($enrollment->offshore_id);
            $this->assertSame(13670, (int) $enrollment->direct_deposit_tax_id);
            $this->assertSame(78, (int) $enrollment->fallback_tax_id);
        } finally {
            if (Schema::hasColumn('direct_deposit_enrollments', 'offshore_id')) {
                $migration->down();
            }
        }
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_09_10_003647_add_offshore_direct_deposit_configuration.php'
        );
    }
}

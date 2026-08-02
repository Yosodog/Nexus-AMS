<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class NormalizeCityGrantRequirementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_normalizes_legacy_and_existing_city_grant_requirement_trees(): void
    {
        $existingTree = [
            'group' => 'any',
            'rules' => [[
                'field' => 'color',
                'operator' => 'eq',
                'value' => 'BLUE',
                'message' => '',
            ]],
        ];

        DB::table('city_grants')->insert([
            [
                'id' => 901,
                'description' => 'Legacy',
                'enabled' => true,
                'grant_amount' => 100,
                'city_number' => 6,
                'requirements' => json_encode([
                    'required_projects' => ['Urban Planning'],
                    'minimum_infra_per_city' => 1800,
                    'project_bits' => 1,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 902,
                'description' => 'Empty',
                'enabled' => true,
                'grant_amount' => 100,
                'city_number' => 7,
                'requirements' => json_encode([
                    'required_projects' => [],
                    'project_bits' => 0,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 903,
                'description' => 'Normalized',
                'enabled' => true,
                'grant_amount' => 100,
                'city_number' => 8,
                'requirements' => json_encode($existingTree, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 904,
                'description' => 'Legacy aliases',
                'enabled' => true,
                'grant_amount' => 100,
                'city_number' => 9,
                'requirements' => json_encode([
                    'NRF' => true,
                    'irondome' => false,
                    'mmrScore' => 75,
                    'infPerCity' => 2000,
                    'govSupportAgency' => 1,
                    'bureauDomesticAffairs' => 'true',
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->migration()->up();

        $legacy = json_decode((string) DB::table('city_grants')->where('id', 901)->value('requirements'), true);
        $empty = DB::table('city_grants')->where('id', 902)->value('requirements');
        $normalized = json_decode((string) DB::table('city_grants')->where('id', 903)->value('requirements'), true);
        $aliased = json_decode((string) DB::table('city_grants')->where('id', 904)->value('requirements'), true);

        $this->assertSame('all', $legacy['group']);
        $this->assertEqualsCanonicalizing(
            ['projects', 'avg_infrastructure_per_city'],
            collect($legacy['rules'])->pluck('field')->all()
        );
        $this->assertNull($empty);
        $this->assertSame($existingTree, $normalized);

        $aliasedRules = collect($aliased['rules'])->keyBy('field');

        $this->assertSame(75, $aliasedRules['mmr_score']['value']);
        $this->assertSame(2000, $aliasedRules['avg_infrastructure_per_city']['value']);
        $this->assertEqualsCanonicalizing([
            'Nuclear Research Facility',
            'Government Support Agency',
            'Bureau of Domestic Affairs',
        ], $aliasedRules['projects']['value']);
    }

    public function test_migration_aborts_for_unsupported_non_empty_legacy_requirements(): void
    {
        $legacyRequirements = json_encode(
            ['required_projects' => ['Urban Planning'], 'project_bits' => 1],
            JSON_THROW_ON_ERROR
        );

        DB::table('city_grants')->insert([
            [
                'id' => 903,
                'description' => 'Legacy before unsupported',
                'enabled' => true,
                'grant_amount' => 100,
                'city_number' => 8,
                'requirements' => $legacyRequirements,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 904,
                'description' => 'Unsupported',
                'enabled' => true,
                'grant_amount' => 100,
                'city_number' => 9,
                'requirements' => json_encode(['unknown_requirement' => true], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        try {
            $this->migration()->up();
            $this->fail('The migration should reject unsupported legacy requirement keys.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'City grant 904 has unsupported legacy requirement keys: unknown_requirement',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            json_decode($legacyRequirements, true),
            json_decode((string) DB::table('city_grants')->where('id', 903)->value('requirements'), true)
        );
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_01_220000_normalize_city_grant_requirements_to_rule_trees.php');
    }
}

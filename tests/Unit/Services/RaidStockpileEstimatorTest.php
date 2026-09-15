<?php

namespace Tests\Unit\Services;

use App\Services\RaidStockpileEstimator;
use Carbon\CarbonImmutable;
use Tests\UnitTestCase;

class RaidStockpileEstimatorTest extends UnitTestCase
{
    private RaidStockpileEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->estimator = new RaidStockpileEstimator;
    }

    public function test_reconstructs_victory_loot_and_applies_dated_depletion_and_production(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'daily_output' => ['money' => 10, 'coal' => 5],
                'daily_expenses' => [],
            ],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'source_war_id' => 101,
                'source_attack_id' => 1001,
                'kind' => 'victory',
                'resources' => ['money' => 100, 'coal' => 20],
                'loot_fraction' => 0.10,
                'modifier_known' => true,
                'provenance_war_ids' => [101],
            ]],
            attacks: [[
                'id' => 2001,
                'war_id' => 202,
                'date' => '2026-01-02T00:00:00Z',
                'def_id' => 42,
                'type' => 'GROUND',
                'money_stolen' => 50,
                'coal_looted' => 10,
            ]],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
        );

        $this->assertSame(870.0, $result['resources']['money']);
        $this->assertSame(180.0, $result['resources']['coal']);
        $this->assertSame('partial', $result['status']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $result['observed_at']);
        $this->assertContains(101, $result['provenance_war_ids']);
        $this->assertContains(202, $result['provenance_war_ids']);
    }

    public function test_calculation_evidence_shows_the_frozen_baseline_without_claiming_separate_projection_inputs(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'daily_output' => ['money' => 10],
            ],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'source_war_id' => 101,
                'source_attack_id' => 1001,
                'kind' => 'victory',
                'resources' => ['money' => 100],
                'loot_fraction' => 0.10,
                'modifier_known' => true,
                'war_type' => 'RAID',
                'fraction_source' => 'historical_inputs',
                'fraction_inputs' => [
                    'war_type' => ['value' => 'RAID', 'source' => 'war_record'],
                ],
            ]],
            attacks: [[
                'id' => 2001,
                'war_id' => 202,
                'date' => '2026-01-02T00:00:00Z',
                'att_id' => 99,
                'def_id' => 42,
                'victor' => 99,
                'type' => 'GROUND',
                'money_stolen' => 50,
            ]],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
        );

        $calculation = $result['calculation'];
        $observation = $calculation['observations'][0];
        $money = $calculation['resources']['money'];

        $this->assertSame('2026-01-03T00:00:00+00:00', $calculation['as_of']);
        $this->assertSame('unverified', $calculation['coverage']['history']['status']);
        $this->assertSame('historical_inputs', $observation['fraction_source']);
        $this->assertSame(100.0, $observation['looted']);
        $this->assertSame(0.10, $observation['fraction']);
        $this->assertSame(1_000.0, $observation['before_loot']);
        $this->assertSame(900.0, $observation['post_loot']);
        $this->assertSame(900.0, $money['baseline_balance']);
        $this->assertSame(-30.0, $money['net_change']);
        $this->assertNull($money['production']);
        $this->assertNull($money['depletion']);
        $this->assertSame('RAID', $observation['war_type']);
        $this->assertSame('war_record', $observation['fraction_inputs']['war_type']['source']);
    }

    public function test_latest_partial_observation_replaces_only_the_resource_it_contains_and_source_attack_is_not_double_counted(): void
    {
        $result = $this->estimate(
            nation: ['id' => 42],
            observations: [
                [
                    'id' => 1,
                    'observed_at' => '2026-01-01T00:00:00Z',
                    'source_war_id' => 101,
                    'source_attack_id' => 1001,
                    'kind' => 'victory',
                    'resources' => ['money' => 100],
                    'loot_fraction' => 0.10,
                    'modifier_known' => true,
                ],
                [
                    'id' => 2,
                    'observed_at' => '2026-01-02T00:00:00Z',
                    'kind' => 'stockpile',
                    'resources' => ['money' => 500, 'coal' => 50],
                ],
            ],
            attacks: [[
                'id' => 1001,
                'war_id' => 101,
                'date' => '2026-01-01T00:00:00Z',
                'def_id' => 42,
                'type' => 'VICTORY',
                'money_looted' => 100,
            ]],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
        );

        $this->assertSame(500.0, $result['resources']['money']);
        $this->assertSame(50.0, $result['resources']['coal']);
        $this->assertNotContains(101, $result['provenance_war_ids']);
    }

    public function test_excluded_war_and_transitive_provenance_are_removed_from_observations_and_attacks(): void
    {
        $result = $this->estimate(
            nation: ['id' => 42],
            observations: [
                [
                    'id' => 1,
                    'observed_at' => '2026-01-01T00:00:00Z',
                    'source_war_id' => 7,
                    'kind' => 'stockpile',
                    'resources' => ['money' => 100],
                ],
                [
                    'id' => 2,
                    'observed_at' => '2026-01-02T00:00:00Z',
                    'source_war_id' => 8,
                    'provenance_war_ids' => [8],
                    'kind' => 'stockpile',
                    'resources' => ['money' => 300],
                ],
                [
                    'id' => 3,
                    'observed_at' => '2026-01-03T00:00:00Z',
                    'source_war_id' => 9,
                    'provenance_war_ids' => [9, 7],
                    'kind' => 'stockpile',
                    'resources' => ['money' => 900],
                ],
            ],
            attacks: [
                [
                    'id' => 1,
                    'war_id' => 7,
                    'date' => '2026-01-04T00:00:00Z',
                    'def_id' => 42,
                    'type' => 'GROUND',
                    'money_stolen' => 90,
                ],
                [
                    'id' => 2,
                    'war_id' => 8,
                    'date' => '2026-01-04T00:00:00Z',
                    'def_id' => 42,
                    'type' => 'GROUND',
                    'money_stolen' => 25,
                ],
            ],
            asOf: CarbonImmutable::parse('2026-01-05T00:00:00Z'),
            excludedWarId: 7,
        );

        $this->assertSame(275.0, $result['resources']['money']);
        $this->assertSame([8], $result['provenance_war_ids']);
    }

    public function test_unknown_victory_modifier_produces_explicit_range(): void
    {
        $result = $this->estimate(
            nation: ['id' => 42],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'victory',
                'resources' => ['money' => 100, 'munitions' => 100],
                'loot_fraction' => null,
                'modifier_known' => false,
            ]],
            attacks: [],
            asOf: CarbonImmutable::parse('2026-01-01T00:00:00Z'),
        );

        $this->assertSame(900.0, $result['resources']['money']);
        $this->assertSame(400.0, $result['scenarios']['lower']['money']);
        $this->assertSame(1900.0, $result['scenarios']['upper']['money']);
        $this->assertSame('low', $result['confidence']);
        $warnings = array_values(array_filter($result['assumptions'], fn (string $text): bool => str_contains($text, 'unknown historical modifiers')));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('5%-20%', $warnings[0]);
        $this->assertTrue(collect($result['assumptions'])->contains(fn (string $assumption): bool => str_contains($assumption, 'unknown historical modifiers')));
    }

    public function test_manufacturing_process_is_constrained_by_its_own_inputs_without_scaling_raw_output(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'daily_output' => ['gasoline' => 5],
                'daily_expenses' => ['oil' => 3],
                'production_processes' => [[
                    'output' => ['gasoline' => 5],
                    'inputs' => ['oil' => 3, 'money' => 100],
                ]],
            ],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'stockpile',
                'resources' => ['oil' => 2, 'gasoline' => 0, 'money' => 1_000],
            ]],
            attacks: [],
            asOf: CarbonImmutable::parse('2026-01-02T00:00:00Z'),
        );

        $this->assertEqualsWithDelta(0.0, $result['resources']['oil'], 0.000001);
        $this->assertEqualsWithDelta(3.3333333333, $result['resources']['gasoline'], 0.000001);
        $this->assertEqualsWithDelta(933.3333333333, $result['resources']['money'], 0.000001);
    }

    public function test_manufacturing_processes_share_the_remaining_input_budget(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'daily_output' => ['steel' => 10, 'munitions' => 5],
                'daily_expenses' => ['iron' => 20],
                'production_processes' => [
                    ['output' => ['steel' => 10], 'inputs' => ['iron' => 10]],
                    ['output' => ['munitions' => 5], 'inputs' => ['iron' => 10]],
                ],
            ],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'stockpile',
                'resources' => ['iron' => 10, 'steel' => 0, 'munitions' => 0],
            ]],
            attacks: [],
            asOf: CarbonImmutable::parse('2026-01-02T00:00:00Z'),
        );

        $this->assertEqualsWithDelta(0.0, $result['resources']['iron'], 0.000001);
        $this->assertEqualsWithDelta(10.0, $result['resources']['steel'], 0.000001);
        $this->assertEqualsWithDelta(0.0, $result['resources']['munitions'], 0.000001);
    }

    public function test_vacation_mode_suppresses_accrual_and_alliance_loot_does_not_reduce_nation_stockpile(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'vacation_mode_turns' => 12,
                'daily_output' => ['money' => 100],
            ],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'stockpile',
                'resources' => ['money' => 100],
            ]],
            attacks: [[
                'id' => 2,
                'war_id' => 22,
                'date' => '2026-01-02T00:00:00Z',
                'def_id' => 42,
                'type' => 'ALLIANCELOOT',
                'money_looted' => 90,
            ]],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
        );

        $this->assertSame(200.0, $result['resources']['money']);
        $this->assertNull($result['bank_resources']);
        $this->assertContains(22, $result['provenance_war_ids']);
    }

    public function test_context_vacation_window_resumes_once_after_its_absolute_end_across_attack_boundaries(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'production_context' => [[
                    'observed_at' => '2026-01-01T00:00:00Z',
                    'vacation_mode_turns' => 1,
                    'daily_output' => ['money' => 100],
                ]],
            ],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'stockpile',
                'resources' => ['money' => 0],
            ]],
            attacks: [[
                'id' => 2,
                'war_id' => 22,
                'date' => '2026-01-01T01:00:00Z',
                'def_id' => 42,
                'type' => 'GROUND',
                'money_stolen' => 0,
            ]],
            asOf: CarbonImmutable::parse('2026-01-01T03:00:00Z'),
        );

        $this->assertEqualsWithDelta(100 / 24, $result['resources']['money'], 0.000001);
    }

    public function test_vacation_mode_turns_resume_production_after_the_remaining_period(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'vacation_mode_turns' => 12,
                'daily_output' => ['money' => 100],
            ],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'stockpile',
                'resources' => ['money' => 100],
            ]],
            attacks: [],
            asOf: CarbonImmutable::parse('2026-01-04T00:00:00Z'),
        );

        $this->assertSame(300.0, $result['resources']['money']);
    }

    public function test_returns_a_nullable_absolute_balance_when_no_clean_baseline_exists(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'daily_output' => ['money' => 100],
            ],
            observations: [],
            attacks: [],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
        );

        $this->assertNull($result['resources']);
        $this->assertNull($result['scenarios']['base']['money']);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('low', $result['confidence']);
    }

    public function test_uses_a_bounded_low_confidence_production_estimate_without_loot_history(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'last_active' => '2026-01-01T00:00:00Z',
                'daily_output' => ['money' => 100],
                'daily_expenses' => ['money' => 20],
            ],
            observations: [],
            attacks: [],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
        );

        $this->assertSame(160.0, $result['resources']['money']);
        $this->assertSame(0.0, $result['scenarios']['lower']['money']);
        $this->assertSame(200.0, $result['scenarios']['upper']['money']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame('partial', $result['status']);
        $this->assertTrue(collect($result['assumptions'])->contains(fn (string $assumption): bool => str_contains($assumption, 'zero starting balance')));
    }

    public function test_zero_victory_loot_does_not_create_a_false_zero_stockpile_baseline(): void
    {
        $result = $this->estimate(
            nation: ['id' => 42],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'victory',
                'resources' => ['money' => 0, 'coal' => 0],
                'loot_fraction' => 0.10,
                'modifier_known' => true,
            ]],
            attacks: [],
            asOf: CarbonImmutable::parse('2026-01-01T00:00:00Z'),
        );

        $this->assertNull($result['resources']);
        $this->assertSame('unknown', $result['status']);
    }

    public function test_source_attack_is_skipped_only_for_the_resource_used_by_a_partial_observation(): void
    {
        $result = $this->estimate(
            nation: ['id' => 42],
            observations: [
                [
                    'id' => 1,
                    'observed_at' => '2026-01-01T00:00:00Z',
                    'kind' => 'stockpile',
                    'resources' => ['coal' => 100],
                ],
                [
                    'id' => 2,
                    'observed_at' => '2026-01-02T00:00:00Z',
                    'source_attack_id' => 1001,
                    'kind' => 'victory',
                    'resources' => ['money' => 100],
                    'loot_fraction' => 0.10,
                    'modifier_known' => true,
                ],
            ],
            attacks: [[
                'id' => 1001,
                'war_id' => 101,
                'date' => '2026-01-02T12:00:00Z',
                'def_id' => 42,
                'type' => 'VICTORY',
                'money_looted' => 100,
                'coal_looted' => 10,
            ]],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
        );

        $this->assertSame(900.0, $result['resources']['money']);
        $this->assertSame(90.0, $result['resources']['coal']);
    }

    public function test_flattened_and_nested_attack_payloads_are_not_counted_twice(): void
    {
        $result = $this->estimate(
            nation: ['id' => 42],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'stockpile',
                'resources' => ['money' => 100, 'coal' => 100],
            ]],
            attacks: [[
                'id' => 1001,
                'war_id' => 101,
                'date' => '2026-01-02T00:00:00Z',
                'def_id' => 42,
                'type' => 'GROUND',
                'money_stolen' => 20,
                'money_looted' => 5,
                'coal_looted' => 10,
                'resource_looted' => ['coal' => 10],
                'loot_info' => json_encode([
                    'money_stolen' => 20,
                    'money_looted' => 5,
                    'resource_looted' => ['coal' => 10],
                ], JSON_THROW_ON_ERROR),
            ]],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
        );

        $this->assertSame(75.0, $result['resources']['money']);
        $this->assertSame(90.0, $result['resources']['coal']);
    }

    public function test_depletion_uses_the_losing_side_and_ignores_loot_from_a_successful_attack(): void
    {
        $result = $this->estimate(
            nation: ['id' => 42],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'stockpile',
                'resources' => ['money' => 1_000],
            ]],
            attacks: [
                [
                    'id' => 1001,
                    'war_id' => 101,
                    'date' => '2026-01-02T00:00:00Z',
                    'att_id' => 42,
                    'def_id' => 99,
                    'victor' => 42,
                    'type' => 'VICTORY',
                    'money_looted' => 500,
                ],
                [
                    'id' => 1002,
                    'war_id' => 102,
                    'date' => '2026-01-02T00:00:00Z',
                    'att_id' => 99,
                    'def_id' => 42,
                    'victor' => 99,
                    'type' => 'GROUND',
                    'money_stolen' => 100,
                ],
                [
                    'id' => 1003,
                    'war_id' => 103,
                    'date' => '2026-01-02T00:00:00Z',
                    'att_id' => 42,
                    'def_id' => 98,
                    'victor' => 98,
                    'type' => 'VICTORY',
                    'money_looted' => 100,
                ],
            ],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
        );

        $this->assertSame(800.0, $result['resources']['money']);
    }

    public function test_excluded_production_context_does_not_change_the_projection(): void
    {
        $result = $this->estimate(
            nation: [
                'id' => 42,
                'production_context' => [[
                    'observed_at' => '2026-01-02T00:00:00Z',
                    'source_war_id' => 7,
                    'daily_output' => ['money' => 100],
                ]],
                'daily_output' => ['money' => 10],
            ],
            observations: [[
                'id' => 1,
                'observed_at' => '2026-01-01T00:00:00Z',
                'kind' => 'stockpile',
                'resources' => ['money' => 100],
            ]],
            attacks: [],
            asOf: CarbonImmutable::parse('2026-01-03T00:00:00Z'),
            excludedWarId: 7,
        );

        $this->assertSame(120.0, $result['resources']['money']);
    }

    /**
     * @param  array<string, mixed>  $nation
     * @param  list<array<string, mixed>>  $observations
     * @param  list<array<string, mixed>>  $attacks
     * @return array<string, mixed>
     */
    private function estimate(
        array $nation,
        array $observations,
        array $attacks,
        CarbonImmutable $asOf,
        ?int $excludedWarId = null,
    ): array {
        return $this->estimator->estimate($nation, $observations, $attacks, $asOf, $excludedWarId);
    }
}

<?php

namespace Tests\Unit\Raids;

use App\Support\Raids\RaidPredictionRowMapper;
use Tests\TestCase;

class RaidPredictionRowMapperTest extends TestCase
{
    public function test_evaluated_prediction_maps_range_confidence_stockpile_and_plan(): void
    {
        $payload = RaidPredictionRowMapper::map([
            'id' => 1,
            'capture_status' => 'ready',
            'evaluation_status' => 'complete',
            'expected_net' => '100.00',
            'conservative_net' => '40.00',
            'scenarios' => json_encode([
                ['expected_net' => 50, 'weight' => 1],
                ['expected_net' => 250, 'weight' => 1],
                ['label' => 'no value'],
            ]),
            'simulation_payload' => json_encode([
                'win_probability' => 0.87,
                'approach' => ['actions' => [['type' => 'GROUND'], ['type' => 'Ground'], ['type' => 'AIRVINFRA']]],
            ]),
            'provenance' => json_encode(['confidence' => 'high']),
            'stockpile_snapshot' => json_encode(['resources' => ['money' => 1_000_000], 'confidence' => 'low']),
            'target_snapshot' => json_encode(['id' => 202, 'soldiers' => 100]),
            'context_snapshot' => json_encode(['war_type' => 'RAID']),
        ]);

        $this->assertSame(40.0, $payload['expected_net_low']);
        $this->assertSame(250.0, $payload['expected_net_high']);
        $this->assertSame(0.87, $payload['win_probability']);
        $this->assertSame('high', $payload['confidence']);
        $this->assertSame(
            ['id' => 202, 'soldiers' => 100, 'stockpile' => ['resources' => ['money' => 1_000_000], 'confidence' => 'low']],
            json_decode($payload['target_snapshot'], true),
        );
        $this->assertSame(
            ['war_type' => 'RAID', 'plan' => ['ground', 'ground', 'airvinfra']],
            json_decode($payload['context_snapshot'], true),
        );
        $this->assertArrayNotHasKey('capture_status', $payload);
    }

    public function test_prediction_without_scenarios_uses_expected_net_for_both_bounds(): void
    {
        $payload = RaidPredictionRowMapper::map([
            'expected_net' => 75,
            'conservative_net' => null,
            'scenarios' => null,
            'stockpile_snapshot' => json_encode(['confidence' => 'medium']),
            'target_snapshot' => null,
            'context_snapshot' => null,
        ]);

        $this->assertSame(75.0, $payload['expected_net_low']);
        $this->assertSame(75.0, $payload['expected_net_high']);
        $this->assertSame('medium', $payload['confidence']);
        $this->assertSame(['stockpile' => ['confidence' => 'medium']], json_decode($payload['target_snapshot'], true));
    }

    public function test_failed_evaluation_of_a_ready_capture_becomes_incomplete(): void
    {
        $payload = RaidPredictionRowMapper::map([
            'capture_status' => 'ready',
            'evaluation_status' => 'failed',
            'expected_net' => null,
            'target_snapshot' => json_encode(['id' => 1]),
        ]);

        $this->assertSame('incomplete', $payload['capture_status']);
        $this->assertSame('Prediction evaluation failed.', $payload['capture_reason']);
        $this->assertNull($payload['expected_net_low']);
        $this->assertNull($payload['expected_net_high']);
    }

    public function test_missing_simulation_payload_yields_an_empty_plan_and_no_win_probability(): void
    {
        $payload = RaidPredictionRowMapper::map([
            'capture_status' => 'incomplete',
            'evaluation_status' => 'failed',
            'expected_net' => null,
            'simulation_payload' => null,
            'provenance' => null,
            'stockpile_snapshot' => null,
            'target_snapshot' => null,
            'context_snapshot' => null,
        ]);

        $this->assertNull($payload['win_probability']);
        $this->assertNull($payload['confidence']);
        $this->assertNull($payload['target_snapshot']);
        $this->assertSame(['plan' => []], json_decode($payload['context_snapshot'], true));
        $this->assertArrayNotHasKey('capture_status', $payload);
    }
}

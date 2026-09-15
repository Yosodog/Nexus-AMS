<?php

namespace Tests\Feature;

use App\Models\Nation;
use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use App\Services\RaidFinderService;
use App\Services\RaidIntelligenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RaidFinderPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_availability_reads_shared_state_without_target_query_multiplication(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000]);
        $targets = Nation::factory()->count(12)->create([
            'alliance_id' => null,
            'score' => 1000,
            'color' => 'blue',
            'beige_turns' => 0,
            'vacation_mode_turns' => 0,
            'defensive_wars_count' => 0,
        ]);
        $observedAt = CarbonImmutable::now()->subMinute();
        RaidNationObservation::factory()->create([
            'nation_id' => $own->id,
            'observed_at' => $observedAt,
            'payload' => [
                'id' => $own->id,
                'score' => 1000,
                'alliance_id' => 777,
                'vacation_mode_turns' => 0,
                'beige_turns' => 0,
                'color' => 'blue',
                'offensive_wars_count' => 0,
                'active_wars' => [],
            ],
        ]);
        foreach ($targets as $target) {
            RaidNationObservation::factory()->create([
                'nation_id' => $target->id,
                'observed_at' => $observedAt,
                'payload' => [
                    'id' => $target->id,
                    'score' => 1000,
                    'alliance_id' => null,
                    'vacation_mode_turns' => 0,
                    'beige_turns' => 0,
                    'color' => 'blue',
                    'defensive_wars_count' => 0,
                    'active_wars' => [],
                ],
            ]);
        }

        $service = app(RaidFinderService::class);
        // Warm the policy snapshot on the same finder instance so this check
        // measures target scaling rather than one-time policy setup.
        $service->availability($own->id, (int) $targets->first()->id);
        $targetIds = $targets->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $service->availabilityBatch($own->id, $targetIds, checkLocalWars: true);

        $this->assertCount(12, $result);
        $this->assertTrue(collect($result)->every(fn (array $availability): bool => $availability['eligible'] === true));
        $this->assertLessThanOrEqual(4, $queryCount, "Batch availability executed {$queryCount} queries.");
    }

    public function test_availability_uses_the_shared_declaration_score_contract(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000]);
        $target = Nation::factory()->create(['alliance_id' => null, 'score' => 2400, 'color' => 'blue']);
        $observedAt = CarbonImmutable::now()->subMinute()->toIso8601String();
        $current = [
            $own->id => [
                'score' => 1000,
                'alliance_id' => 777,
                'vacation_mode_turns' => 0,
                'offensive_wars_count' => 0,
                'active_wars' => [],
                'observed_at' => $observedAt,
            ],
            $target->id => [
                'score' => 2400,
                'alliance_id' => 0,
                'vacation_mode_turns' => 0,
                'beige_turns' => 0,
                'color' => 'blue',
                'defensive_wars_count' => 0,
                'active_wars' => [],
                'observed_at' => $observedAt,
            ],
        ];
        $service = app(RaidFinderService::class);

        $this->assertTrue($service->availability($own->id, $target->id, $current)['eligible']);
        $current[$target->id]['score'] = 2601;
        $this->assertFalse($service->availability($own->id, $target->id, $current)['eligible']);
    }

    public function test_batch_availability_only_marks_pairs_missing_from_the_snapshot_as_stale(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000]);
        $fresh = Nation::factory()->create([
            'alliance_id' => null,
            'score' => 2400,
            'color' => 'blue',
            'beige_turns' => 0,
            'vacation_mode_turns' => 0,
            'defensive_wars_count' => 0,
        ]);
        $missing = Nation::factory()->create([
            'alliance_id' => null,
            'score' => 1000,
            'color' => 'blue',
            'beige_turns' => 0,
            'vacation_mode_turns' => 0,
            'defensive_wars_count' => 0,
        ]);
        $observedAt = CarbonImmutable::now()->subMinute()->toIso8601String();
        $current = [
            $own->id => [
                'id' => $own->id,
                'score' => 1000,
                'alliance_id' => 777,
                'vacation_mode_turns' => 0,
                'offensive_wars_count' => 0,
                'active_wars' => [],
                'observed_at' => $observedAt,
            ],
            $fresh->id => [
                'id' => $fresh->id,
                'score' => 2400,
                'alliance_id' => 0,
                'vacation_mode_turns' => 0,
                'beige_turns' => 0,
                'color' => 'blue',
                'defensive_wars_count' => 0,
                'active_wars' => [],
                'observed_at' => $observedAt,
            ],
        ];

        $result = app(RaidFinderService::class)->availabilityBatch(
            $own->id,
            [$fresh->id, $missing->id],
            $current,
        );

        $this->assertTrue($result[$fresh->id]['eligible']);
        $this->assertNotContains('Availability needs a fresh check.', $result[$fresh->id]['reasons']);
        $this->assertNull($result[$missing->id]['eligible']);
        $this->assertContains('Availability needs a fresh check.', $result[$missing->id]['reasons']);
    }

    public function test_finder_freezes_at_most_candidate_limit_and_keeps_an_unseen_target_in_the_pool(): void
    {
        config(['raids.candidate_limit' => 2]);
        Cache::forever('alliances:membership:ids', [777]);
        $own = Nation::factory()->create(['alliance_id' => 777, 'score' => 1000]);
        $historical = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue']);
        $unseen = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue']);
        $otherUnseen = Nation::factory()->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue']);
        Nation::factory()->count(100)->create(['alliance_id' => null, 'score' => 1000, 'color' => 'blue']);
        $observedAt = CarbonImmutable::now()->subMinute()->toIso8601String();
        $attacker = [
            'id' => $own->id,
            'score' => 1000,
            'alliance_id' => 777,
            'vacation_mode_turns' => 0,
            'offensive_wars_count' => 0,
            'active_wars' => [],
            'observed_at' => $observedAt,
        ];
        $targetSnapshots = [];
        foreach ([$historical, $unseen, $otherUnseen] as $target) {
            $targetSnapshots[$target->id] = [
                'id' => $target->id,
                'score' => 1000,
                'alliance_id' => 0,
                'vacation_mode_turns' => 0,
                'beige_turns' => 0,
                'color' => 'blue',
                'defensive_wars_count' => 0,
                'active_wars' => [],
                'observed_at' => $observedAt,
            ];
        }
        RaidAttackObservation::factory()->create([
            'att_id' => 9001,
            'def_id' => $historical->id,
            'occurred_at' => CarbonImmutable::now()->subDay(),
            'observed_at' => CarbonImmutable::now()->subMinute(),
            'payload' => [
                'type' => 'VICTORY',
                'victor' => 9001,
                'att_id' => 9001,
                'def_id' => $historical->id,
                'date' => CarbonImmutable::now()->subDay()->toIso8601String(),
                'money_looted' => 500000,
            ],
        ]);
        $freezeIds = [];
        $previousInput = null;
        $intelligence = Mockery::mock(RaidIntelligenceService::class);
        $intelligence->shouldReceive('nationAt')->once()->andReturn($attacker);
        $intelligence->shouldReceive('nationsAt')->andReturnUsing(function (array $ids) use ($targetSnapshots): array {
            $this->assertLessThanOrEqual(50, count($ids));

            return array_intersect_key($targetSnapshots, array_flip($ids));
        });
        $intelligence->shouldReceive('freeze')->times(2)->andReturnUsing(function (int $attackerId, int $targetId) use (&$freezeIds, &$previousInput): array {
            $this->assertNull($previousInput?->get(), 'Release the previous input before freezing another target.');
            $marker = new \stdClass;
            $previousInput = \WeakReference::create($marker);
            $freezeIds[] = $targetId;

            return [
                'attacker' => [],
                'target' => [],
                'stockpile' => [],
                'prices' => [],
                'context' => ['retention_marker' => $marker],
                'status' => 'incomplete',
            ];
        });
        $this->app->instance(RaidIntelligenceService::class, $intelligence);

        $results = app(RaidFinderService::class)->findTargets($own->id);

        $this->assertCount(2, $freezeIds);
        $this->assertContains($historical->id, $freezeIds);
        $this->assertContains($unseen->id, $freezeIds);
        $this->assertCount(2, $results);
    }
}

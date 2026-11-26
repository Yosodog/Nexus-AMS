<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RaidOutcomeAttack;
use App\Models\RaidPrediction;
use App\Models\War;
use App\Services\QueryService;
use App\Services\RaidOutcomeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RaidTerminalEvidenceRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const INVALID_ATTACK_FIELDS = [
        'att_alum_used',
        'def_alum_used',
        'att_steel_used',
        'def_steel_used',
        'att_infra_destroyed',
        'def_infra_destroyed',
        'att_infra_destroyed_value',
        'def_infra_destroyed_value',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['raids.reconciliation_refresh_seconds' => 600]);
        $this->app->instance(QueryService::class, new QueryService('test-key'));
    }

    public function test_refresh_query_only_asks_for_fields_the_attack_type_exposes(): void
    {
        Http::fake(['*' => Http::response($this->warResponse(91101, []), 200)]);
        $prediction = $this->terminalPrediction(91101);

        app(RaidOutcomeService::class)->reconcile($prediction);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $query = (string) $request['query'];
            foreach (self::INVALID_ATTACK_FIELDS as $field) {
                if (str_contains($query, $field)) {
                    return false;
                }
            }

            return str_contains($query, 'attacks') && str_contains($query, 'att_gas_used');
        });
    }

    public function test_successful_refresh_records_evidence_and_finalizes_without_aluminum_or_steel_attack_data(): void
    {
        Http::fake(['*' => Http::response($this->warResponse(91102, [[
            'id' => 92102,
            'date' => '2026-09-13 11:00:00',
            'att_id' => 101,
            'def_id' => 202,
            'type' => 'VICTORY',
            'victor' => 101,
            'success' => 3,
            'money_looted' => 100,
            'att_gas_used' => 4,
            'att_mun_used' => 6,
            'def_gas_used' => 0,
            'def_mun_used' => 0,
        ]]), 200)]);
        $prediction = $this->terminalPrediction(91102, [
            'att_money_looted' => 100,
            'att_gas_used' => 4,
            'att_mun_used' => 6,
            'att_alum_used' => 12,
            'att_steel_used' => 9,
            'def_alum_used' => 3,
            'def_steel_used' => 2,
            'def_gas_used' => 0,
            'def_mun_used' => 0,
        ]);

        $result = app(RaidOutcomeService::class)->reconcile($prediction);

        $this->assertSame(1, RaidOutcomeAttack::query()->where('war_id', 91102)->count());
        $this->assertSame('refreshed', data_get($result?->outcome_metadata, 'evidence_refresh.status'));
        $this->assertSame(1, data_get($result?->outcome_metadata, 'evidence_refresh.count'));
        $this->assertSame('complete', data_get($result?->outcome_metadata, 'evidence_status'));
        $this->assertNotNull($result?->outcome_finalized_at);
        $this->assertArrayNotHasKey('aluminum', (array) $result?->actual_cost_resources);
        $this->assertArrayNotHasKey('steel', (array) $result?->actual_cost_resources);
        $this->assertSame(4.0, (float) $result?->actual_cost_resources['gasoline']);
    }

    public function test_aluminum_and_steel_are_still_compared_when_an_attack_reports_them(): void
    {
        Http::fake(['*' => Http::response($this->warResponse(91103, []), 200)]);
        $prediction = $this->terminalPrediction(91103, [
            'att_money_looted' => 100,
            'att_alum_used' => 12,
        ]);

        $result = app(RaidOutcomeService::class)->recordPayload([
            'id' => 92103,
            'war_id' => 91103,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'VICTORY',
            'victor' => 101,
            'money_looted' => 100,
            'att_alum_used' => 5,
        ]);

        $this->assertSame('incomplete', data_get($result?->prediction?->outcome_metadata, 'evidence_status'));
        $this->assertArrayHasKey('attacker.costs.aluminum', (array) data_get($result?->prediction?->outcome_metadata, 'evidence_mismatches'));
        $this->assertNotNull($prediction->refresh()->id);
    }

    public function test_a_war_with_failing_refreshes_is_not_retried_beyond_the_attempt_bound(): void
    {
        config(['raids.reconciliation_max_refresh_attempts' => 3]);
        Http::fake(['*' => Http::response('upstream unavailable', 500)]);
        $service = new class(...$this->serviceArguments()) extends QueryService
        {
            public int $initialDelay = 0;

            public int $maxRetries = 0;
        };
        $this->app->instance(QueryService::class, $service);
        $prediction = $this->terminalPrediction(91104);

        $now = CarbonImmutable::parse('2026-10-01 12:00:00');
        for ($i = 0; $i < 20; $i++) {
            CarbonImmutable::setTestNow($now->addHours($i * 8));
            $result = app(RaidOutcomeService::class)->reconcile($prediction);
        }
        CarbonImmutable::setTestNow();

        Http::assertSentCount(3);
        $this->assertSame(3, data_get($result?->outcome_metadata, 'evidence_refresh.attempt_count'));
        $this->assertSame('failed', data_get($result?->outcome_metadata, 'evidence_refresh.status'));
    }

    public function test_failed_refreshes_back_off_before_the_attempt_bound_is_reached(): void
    {
        Http::fake(['*' => Http::response($this->warResponse(91105, []), 200)]);
        $prediction = $this->terminalPrediction(91105);
        $start = CarbonImmutable::parse('2026-10-01 12:00:00');

        foreach ([0, 5, 11, 15, 25, 45] as $minutes) {
            CarbonImmutable::setTestNow($start->addMinutes($minutes));
            app(RaidOutcomeService::class)->reconcile($prediction);
        }
        CarbonImmutable::setTestNow();

        // Attempts at +0, +11 (after 10m), +45 (after a further 20m cooldown elapsed twice over).
        Http::assertSentCount(3);
    }

    public function test_a_later_refresh_within_the_bound_can_still_complete_the_evidence(): void
    {
        config(['raids.reconciliation_refresh_seconds' => 60]);
        Http::fake(['*' => Http::sequence()
            ->push($this->warResponse(91106, []), 200)
            ->push($this->warResponse(91106, [[
                'id' => 92106,
                'date' => '2026-09-13 11:00:00',
                'att_id' => 101,
                'def_id' => 202,
                'type' => 'VICTORY',
                'victor' => 101,
                'money_looted' => 100,
            ]]), 200),
        ]);
        $prediction = $this->terminalPrediction(91106, ['att_money_looted' => 100]);

        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        $first = app(RaidOutcomeService::class)->reconcile($prediction);
        $this->assertSame('empty', data_get($first?->outcome_metadata, 'evidence_refresh.status'));
        $this->assertNull($first?->outcome_finalized_at);

        CarbonImmutable::setTestNow('2026-10-01 12:02:00');
        $second = app(RaidOutcomeService::class)->reconcile($prediction);
        CarbonImmutable::setTestNow();

        $this->assertSame('refreshed', data_get($second?->outcome_metadata, 'evidence_refresh.status'));
        $this->assertSame(2, data_get($second?->outcome_metadata, 'evidence_refresh.attempt_count'));
        $this->assertNotNull($second?->outcome_finalized_at);
    }

    public function test_a_rejected_query_is_logged_once_and_skips_the_remaining_wars_in_the_run(): void
    {
        Http::fake(['*' => Http::response([
            'errors' => [['message' => 'Cannot query field "bogus" on type "WarAttack".']],
        ], 200)]);
        Log::spy();
        $first = $this->terminalPrediction(91107);
        $second = $this->terminalPrediction(91108);
        $third = $this->terminalPrediction(91109);

        $service = app(RaidOutcomeService::class);
        $service->reconcile($first);
        $service->reconcile($second);
        $service->reconcile($third);

        Http::assertSentCount(1);
        Log::shouldHaveReceived('error')->once();
        Log::shouldNotHaveReceived('warning', ['Terminal raid evidence refresh failed.', \Mockery::any()]);
        $this->assertSame('failed', data_get($first->refresh()->outcome_metadata, 'evidence_refresh.status'));
        $this->assertSame([], (array) data_get($second->refresh()->outcome_metadata, 'evidence_refresh', []));
    }

    public function test_transport_failures_do_not_throw_out_of_reconcile(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $service = new class(...$this->serviceArguments()) extends QueryService
        {
            public int $initialDelay = 0;

            public int $maxRetries = 0;
        };
        $this->app->instance(QueryService::class, $service);
        $prediction = $this->terminalPrediction(91110);

        $result = app(RaidOutcomeService::class)->reconcile($prediction);

        $this->assertInstanceOf(RaidPrediction::class, $result);
        $this->assertSame('failed', data_get($result->outcome_metadata, 'evidence_refresh.status'));
        $this->assertSame('incomplete', data_get($result->outcome_metadata, 'evidence_status'));
    }

    public function test_refresh_query_asks_for_ended_wars(): void
    {
        Http::fake(['*' => Http::response($this->warResponse(91111, []), 200)]);

        app(RaidOutcomeService::class)->reconcile($this->terminalPrediction(91111));

        Http::assertSent(fn (Request $request): bool => preg_match('/active:\s*false/', (string) $request['query']) === 1);
    }

    public function test_attempts_made_before_the_query_version_do_not_count_and_the_war_is_refreshed_again(): void
    {
        Http::fake(['*' => Http::response($this->warResponse(91112, [[
            'id' => 92112,
            'date' => '2026-09-13 11:00:00',
            'att_id' => 101,
            'def_id' => 202,
            'type' => 'VICTORY',
            'victor' => 101,
            'money_looted' => 100,
        ]]), 200)]);
        $prediction = $this->terminalPrediction(91112, ['att_money_looted' => 100]);
        $prediction->forceFill(['outcome_metadata' => ['evidence_refresh' => [
            'attempted' => true,
            'attempted_at' => CarbonImmutable::now()->subHour()->toIso8601String(),
            'attempt_count' => 6,
            'status' => 'unavailable',
            'count' => 0,
            'error' => 'The terminal war was not returned by the bounded refresh.',
        ]]])->saveQuietly();

        $result = app(RaidOutcomeService::class)->reconcile($prediction);

        Http::assertSentCount(1);
        $this->assertSame('refreshed', data_get($result?->outcome_metadata, 'evidence_refresh.status'));
        $this->assertSame(1, data_get($result?->outcome_metadata, 'evidence_refresh.attempt_count'));
        $this->assertSame(2, data_get($result?->outcome_metadata, 'evidence_refresh.query_version'));
        $this->assertNotNull($result?->outcome_finalized_at);
    }

    public function test_a_war_the_api_does_not_return_stops_at_the_bound_under_the_current_query(): void
    {
        config(['raids.reconciliation_max_refresh_attempts' => 3]);
        Http::fake(['*' => Http::response(['data' => ['wars' => [
            'data' => [],
            'paginatorInfo' => ['perPage' => 100, 'count' => 0, 'lastPage' => 1],
        ]]], 200)]);
        $prediction = $this->terminalPrediction(91113);

        $now = CarbonImmutable::parse('2026-10-01 12:00:00');
        for ($i = 0; $i < 20; $i++) {
            CarbonImmutable::setTestNow($now->addHours($i * 8));
            $result = app(RaidOutcomeService::class)->reconcile($prediction);
        }
        CarbonImmutable::setTestNow();

        Http::assertSentCount(3);
        $this->assertSame(3, data_get($result?->outcome_metadata, 'evidence_refresh.attempt_count'));
        $this->assertSame(
            'The terminal war was not returned by the bounded refresh.',
            data_get($result?->outcome_metadata, 'evidence_refresh.error'),
        );
    }

    /** @return list<string> */
    private function serviceArguments(): array
    {
        return ['test-key'];
    }

    /**
     * @param  list<array<string, mixed>>  $attacks
     * @return array<string, mixed>
     */
    private function warResponse(int $warId, array $attacks): array
    {
        return ['data' => ['wars' => [
            'data' => [['id' => $warId, 'att_id' => 101, 'def_id' => 202, 'attacks' => $attacks]],
            'paginatorInfo' => ['perPage' => 100, 'count' => 1, 'lastPage' => 1],
        ]]];
    }

    /** @param array<string, mixed> $warOverrides */
    private function terminalPrediction(int $warId, array $warOverrides = []): RaidPrediction
    {
        $war = War::query()->create([
            'id' => $warId,
            'date' => '2026-09-13 10:00:00',
            'end_date' => '2026-09-13 12:00:00',
            'reason' => 'Terminal evidence refresh test',
            'war_type' => 'RAID',
            'turns_left' => 0,
            'att_id' => 101,
            'att_alliance_id' => 777,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 202,
            'def_alliance_id' => null,
            'def_alliance_position' => 'NOALLIANCE',
            'winner_id' => 101,
            ...$warOverrides,
        ]);

        return RaidPrediction::query()->create([
            'war_id' => $war->id,
            'attacker_nation_id' => 101,
            'target_nation_id' => 202,
            'attacker_alliance_id' => 777,
            'attacker_alliance_position' => 'MEMBER',
            'declared_at' => $war->date,
            'captured_at' => $war->date,
            'capture_status' => RaidPrediction::CAPTURE_READY,
            'attacker_snapshot' => ['id' => 101],
            'target_snapshot' => ['id' => 202, 'stockpile' => ['resources' => ['money' => 1_000_000]]],
            'price_snapshot' => [
                'acquisition' => ['money' => 1, 'gasoline' => 1, 'munitions' => 1],
                'liquidation' => ['money' => 1],
            ],
            'context_snapshot' => ['war_type' => 'RAID'],
            'expected_net' => 100,
        ]);
    }
}

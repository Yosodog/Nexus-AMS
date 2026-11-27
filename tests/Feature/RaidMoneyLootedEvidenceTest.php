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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaidMoneyLootedEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['data' => ['wars' => [
            'data' => [],
            'paginatorInfo' => ['perPage' => 100, 'count' => 0, 'lastPage' => 1],
        ]]], 200)]);
        $this->app->instance(QueryService::class, new QueryService('test-key'));
    }

    public function test_a_mirrored_war_total_counts_victory_loot_and_alliance_loot_but_not_ground_cash(): void
    {
        $prediction = $this->terminalPrediction(93001, ['att_money_looted' => 482_395, 'def_money_looted' => 482_395]);

        $this->record(93001, 94001, ['type' => 'GROUND', 'att_id' => 202, 'def_id' => 101, 'money_stolen' => 1_769_470.97]);
        $this->record(93001, 94002, ['type' => 'GROUND', 'money_stolen' => 300_000]);
        $this->record(93001, 94003, ['type' => 'VICTORY', 'victor' => 101, 'money_looted' => 231_000]);
        $outcome = $this->record(93001, 94004, [
            'type' => 'ALLIANCELOOT',
            'victor' => 101,
            'bank_loot' => ['money' => 251_395],
        ]);

        $metadata = $outcome?->prediction?->outcome_metadata;
        $this->assertSame('complete', data_get($metadata, 'evidence_status'));
        $this->assertSame([], data_get($metadata, 'evidence_mismatches'));
        $this->assertEquals(482_395.0, data_get($metadata, 'evidence_expected')['war.money_looted']);
        $this->assertEquals(482_395.0, data_get($metadata, 'evidence_observed')['war.money_looted']);
        $this->assertNotNull($prediction->refresh()->actual_net);
        $this->assertNotNull($prediction->outcome_finalized_at);
    }

    public function test_a_mirrored_total_is_compared_against_every_attack_when_the_defender_won(): void
    {
        $this->terminalPrediction(93008, [
            'winner_id' => 202,
            'att_money_looted' => 900,
            'def_money_looted' => 900,
        ]);

        $this->record(93008, 94012, ['type' => 'VICTORY', 'att_id' => 202, 'def_id' => 101, 'victor' => 202, 'money_looted' => 700]);
        $outcome = $this->record(93008, 94013, [
            'type' => 'ALLIANCELOOT',
            'att_id' => 202,
            'def_id' => 101,
            'victor' => 202,
            'bank_loot' => ['money' => 200],
        ]);

        $metadata = $outcome?->prediction?->outcome_metadata;
        $this->assertSame('complete', data_get($metadata, 'evidence_status'));
        $this->assertEquals(900.0, data_get($metadata, 'evidence_observed')['war.money_looted']);
    }

    public function test_a_mirrored_total_larger_than_the_ledger_stays_incomplete_with_one_mismatch(): void
    {
        $this->terminalPrediction(93002, ['att_money_looted' => 1_000, 'def_money_looted' => 1_000]);

        $outcome = $this->record(93002, 94005, ['type' => 'VICTORY', 'victor' => 101, 'money_looted' => 600]);

        $metadata = $outcome?->prediction?->outcome_metadata;
        $this->assertSame('incomplete', data_get($metadata, 'evidence_status'));
        $this->assertSame(['war.money_looted'], array_keys(data_get($metadata, 'evidence_mismatches')));
        $this->assertNull($outcome?->prediction?->actual_net);
    }

    public function test_distinct_side_totals_are_still_compared_per_participant(): void
    {
        $this->terminalPrediction(93003, ['att_money_looted' => 500, 'def_money_looted' => 40]);

        $this->record(93003, 94005, ['type' => 'VICTORY', 'victor' => 101, 'money_looted' => 500]);
        $outcome = $this->record(93003, 94006, [
            'type' => 'VICTORY',
            'att_id' => 202,
            'def_id' => 101,
            'victor' => 202,
            'money_looted' => 40,
        ]);

        $metadata = $outcome?->prediction?->outcome_metadata;
        $this->assertSame('complete', data_get($metadata, 'evidence_status'));
        $this->assertArrayHasKey('attacker.money_looted', data_get($metadata, 'evidence_expected'));
        $this->assertArrayHasKey('defender.money_looted', data_get($metadata, 'evidence_expected'));
    }

    public function test_distinct_side_totals_that_do_not_match_name_the_side(): void
    {
        $this->terminalPrediction(93004, ['att_money_looted' => 500, 'def_money_looted' => 40]);

        $outcome = $this->record(93004, 94007, ['type' => 'VICTORY', 'victor' => 101, 'money_looted' => 500]);

        $metadata = $outcome?->prediction?->outcome_metadata;
        $this->assertSame('incomplete', data_get($metadata, 'evidence_status'));
        $this->assertSame(['defender.money_looted'], array_keys(data_get($metadata, 'evidence_mismatches')));
    }

    public function test_incomplete_predictions_are_re_evaluated_locally_without_calling_the_api(): void
    {
        $prediction = $this->terminalPrediction(93006, ['att_money_looted' => 1_000, 'def_money_looted' => 1_000]);
        $this->record(93006, 94009, ['type' => 'GROUND', 'money_stolen' => 4_000]);
        $this->record(93006, 94010, ['type' => 'VICTORY', 'victor' => 101, 'money_looted' => 600]);
        $this->record(93006, 94014, ['type' => 'ALLIANCELOOT', 'victor' => 101, 'bank_loot' => ['money' => 400]]);

        // Put the prediction back into the state older code left it in: finalized
        // earlier, marked incomplete, refresh attempts used up, ledger untouched.
        $finalizedAt = CarbonImmutable::now()->subDays(2);
        $prediction->refresh()->forceFill([
            'actual_net' => null,
            'actual_gross_loot' => null,
            'outcome_finalized_at' => $finalizedAt,
            'outcome_metadata' => [
                'evidence_status' => 'incomplete',
                'evidence_reason' => 'Terminal war aggregates exceed the observed attack ledger.',
                'evidence_refresh' => [
                    'attempted' => true,
                    'attempted_at' => $finalizedAt->toIso8601String(),
                    'attempt_count' => 6,
                    'query_version' => 2,
                    'status' => 'refreshed',
                ],
            ],
        ])->saveQuietly();
        RaidOutcomeAttack::query()->update(['updated_at' => $finalizedAt->subDay()]);
        Http::fake();

        $this->artisan('raid:reconcile-predictions', ['--days' => 365])->assertSuccessful();

        Http::assertNothingSent();
        $prediction->refresh();
        $this->assertSame('complete', data_get($prediction->outcome_metadata, 'evidence_status'));
        $this->assertNotNull($prediction->actual_net);
        $this->assertTrue($prediction->outcome_finalized_at->greaterThan($finalizedAt));
        $this->assertSame(6, data_get($prediction->outcome_metadata, 'evidence_refresh.attempt_count'));
    }

    public function test_complete_finalized_predictions_are_not_reconciled_again_after_the_grace_period(): void
    {
        $prediction = $this->terminalPrediction(93007, ['att_money_looted' => 100, 'def_money_looted' => 100]);
        $this->record(93007, 94011, ['type' => 'VICTORY', 'victor' => 101, 'money_looted' => 100]);
        $finalizedAt = CarbonImmutable::now()->subDays(2);
        $prediction->refresh()->forceFill(['outcome_finalized_at' => $finalizedAt])->saveQuietly();
        RaidOutcomeAttack::query()->update(['updated_at' => $finalizedAt->subDay()]);

        $this->artisan('raid:reconcile-predictions', ['--days' => 365])
            ->expectsOutputToContain('skipped 1 unchanged finalized prediction')
            ->assertSuccessful();

        $this->assertSame($finalizedAt->getTimestamp(), $prediction->refresh()->outcome_finalized_at->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(int $warId, int $attackId, array $overrides): ?RaidOutcomeAttack
    {
        return app(RaidOutcomeService::class)->recordPayload([
            'id' => $attackId,
            'war_id' => $warId,
            'att_id' => 101,
            'def_id' => 202,
            'date' => '2026-09-13 11:00:00',
            'type' => 'VICTORY',
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $warOverrides */
    private function terminalPrediction(int $warId, array $warOverrides = []): RaidPrediction
    {
        $war = War::query()->create([
            'id' => $warId,
            'date' => '2026-09-13 10:00:00',
            'end_date' => '2026-09-13 12:00:00',
            'reason' => 'Money looted comparison test',
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
            'price_snapshot' => ['acquisition' => ['money' => 1], 'liquidation' => ['money' => 1]],
            'context_snapshot' => ['war_type' => 'RAID'],
            'expected_net' => 100,
        ]);
    }
}

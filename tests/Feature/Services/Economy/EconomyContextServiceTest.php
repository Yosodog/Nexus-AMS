<?php

namespace Tests\Feature\Services\Economy;

use App\Exceptions\ProfitabilityContextUnavailable;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use App\Services\Economy\EconomyContextService;
use App\Services\Economy\EconomyRules;
use App\Services\NationProfitabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EconomyContextServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_refreshes_complete_alliance_treasure_and_color_context(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $first = Nation::factory()->create([
            'id' => 501,
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
        ]);
        $second = Nation::factory()->create([
            'id' => 502,
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
        ]);
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'data' => [
                        'treasures' => [
                            [
                                'name' => 'First',
                                'bonus' => 5,
                                'nation_id' => $first->id,
                                'nation' => ['id' => $first->id, 'alliance_id' => 77],
                            ],
                            [
                                'name' => 'Second',
                                'bonus' => 3,
                                'nation_id' => $second->id,
                                'nation' => ['id' => $second->id, 'alliance_id' => 77],
                            ],
                        ],
                    ],
                ])
                ->push([
                    'data' => [
                        'colors' => [
                            ['color' => 'blue', 'turn_bonus' => 125],
                        ],
                    ],
                ]),
        ]);

        $count = app(EconomyContextService::class)->refresh();
        $first->refresh();
        $second->refresh();

        $this->assertSame(2, $count);
        $this->assertEqualsWithDelta(0.078284, $first->treasure_income_modifier, 0.000001);
        $this->assertEqualsWithDelta(0.058284, $second->treasure_income_modifier, 0.000001);
        $this->assertSame(125, $first->color_turn_bonus);
        $this->assertNotNull($first->economy_context_synced_at);
    }

    #[Test]
    public function it_refreshes_more_than_one_thousand_active_nations_in_chunks(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        Nation::factory()->count(1001)->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [
                [
                    'name' => 'Unrelated',
                    'bonus' => 1,
                    'nation_id' => 999999,
                    'nation' => ['alliance_id' => 999],
                ],
            ],
            colors: [
                ['color' => 'blue', 'turn_bonus' => 125],
            ],
        );

        $count = app(EconomyContextService::class)->refresh();

        $this->assertSame(1001, $count);
        $this->assertSame(1001, Nation::query()->whereNotNull('economy_context_synced_at')->count());
        $this->assertSame(1001, Nation::query()->where('color_turn_bonus', 125)->count());
        $this->assertSame(1001, Nation::query()->where('treasure_income_modifier', 0)->count());
        $this->assertSame(1, Nation::query()->distinct()->count('economy_context_synced_at'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_keeps_the_active_nation_set_consistent_when_a_nation_is_added_during_refresh(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        Nation::factory()->create([
            'id' => 501,
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [
                [
                    'name' => 'Unrelated',
                    'bonus' => 1,
                    'nation_id' => 999999,
                    'nation' => ['alliance_id' => 999],
                ],
            ],
            colors: [
                ['color' => 'blue', 'turn_bonus' => 125],
            ],
        );

        $inserted = false;
        DB::listen(function (QueryExecuted $query) use (&$inserted): void {
            $sql = strtolower($query->sql);
            $hasNationCount = preg_match('/select count\(\*\) as [`"]?aggregate[`"]? from [`"]?nations[`"]?/', $sql) === 1;
            $hasNationIdSnapshot = preg_match('/select [`"]?id[`"]? from [`"]?nations[`"]?/', $sql) === 1;
            $hasMaxNationIdSnapshot = preg_match(
                '/select max\([`"]?id[`"]?\) as [`"]?aggregate[`"]? from [`"]?nations[`"]?/',
                $sql
            ) === 1;

            if (
                $inserted
                || (! $hasNationCount && ! $hasNationIdSnapshot && ! $hasMaxNationIdSnapshot)
            ) {
                return;
            }

            $inserted = true;
            Nation::factory()->create([
                'alliance_id' => 77,
                'alliance_position' => 'MEMBER',
                'color' => 'blue',
            ]);
        });

        $count = app(EconomyContextService::class)->refresh();

        $this->assertTrue($inserted);
        $this->assertSame(1, $count);
        $this->assertSame(2, Nation::query()->count());
        $this->assertSame(1, Nation::query()->whereNotNull('economy_context_synced_at')->count());
    }

    #[Test]
    public function it_persists_zero_treasure_modifier_and_zero_color_bonus_as_valid_context(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $nation = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => null,
            'color_turn_bonus' => null,
            'economy_context_synced_at' => null,
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [
                [
                    'name' => 'Unrelated',
                    'bonus' => 1,
                    'nation_id' => 999999,
                    'nation' => ['alliance_id' => 999],
                ],
            ],
            colors: [
                ['color' => 'blue', 'turn_bonus' => 0],
            ],
        );

        $count = app(EconomyContextService::class)->refresh();
        $nation->refresh();

        $this->assertSame(1, $count);
        $this->assertSame(0.0, $nation->treasure_income_modifier);
        $this->assertSame(0, $nation->color_turn_bonus);
        $this->assertTrue(
            $nation->economy_context_synced_at->equalTo(CarbonImmutable::now())
        );
    }

    #[Test]
    public function incomplete_upstream_context_preserves_every_nations_previous_values(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $oldTimestamp = CarbonImmutable::parse('2026-07-31 12:00:00 UTC');
        $nations = Nation::factory()->count(2)->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => 0.25,
            'color_turn_bonus' => 90,
            'economy_context_synced_at' => $oldTimestamp,
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [
                [
                    'name' => 'First',
                    'bonus' => 5,
                    'nation_id' => $nations->first()->id,
                    'nation' => ['alliance_id' => 77],
                ],
            ],
            colors: [
                ['color' => 'red', 'turn_bonus' => 125],
            ],
        );

        try {
            app(EconomyContextService::class)->refresh();
            $this->fail('An incomplete color response should fail synchronization.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Economy context response omitted active nation colors.',
                $exception->getMessage()
            );
        }

        $this->assertSame(2, Nation::query()->where('treasure_income_modifier', 0.25)->count());
        $this->assertSame(2, Nation::query()->where('color_turn_bonus', 90)->count());
        $this->assertSame(
            2,
            Nation::query()->where('economy_context_synced_at', $oldTimestamp->toDateTimeString())->count()
        );
    }

    #[Test]
    public function malformed_zero_like_context_is_rejected_instead_of_becoming_fresh_zeroes(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $oldTimestamp = CarbonImmutable::parse('2026-07-31 12:00:00 UTC');
        $nation = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => 0.25,
            'color_turn_bonus' => 90,
            'economy_context_synced_at' => $oldTimestamp,
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [[
                'name' => 'Unrelated',
                'bonus' => 1,
                'nation_id' => 999999,
                'nation' => ['alliance_id' => 999],
            ]],
            colors: [['color' => 'blue']],
        );

        try {
            app(EconomyContextService::class)->refresh();
            $this->fail('A malformed color bonus should fail synchronization.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Economy context response contained an invalid color bonus.',
                $exception->getMessage()
            );
        }

        $nation->refresh();
        $this->assertSame(0.25, $nation->treasure_income_modifier);
        $this->assertSame(90, $nation->color_turn_bonus);
        $this->assertTrue($nation->economy_context_synced_at->equalTo($oldTimestamp));
    }

    #[Test]
    public function nation_without_a_color_cannot_be_marked_with_fresh_zero_context(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $nation = Nation::factory()->create([
            'color' => '',
            'treasure_income_modifier' => null,
            'color_turn_bonus' => null,
            'economy_context_synced_at' => null,
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [[
                'name' => 'Unrelated',
                'bonus' => 1,
                'nation_id' => 999999,
                'nation' => ['alliance_id' => 999],
            ]],
            colors: [['color' => 'blue', 'turn_bonus' => 0]],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('One or more active nations are missing a color.');

        try {
            app(EconomyContextService::class)->refresh();
        } finally {
            $nation->refresh();
            $this->assertNull($nation->treasure_income_modifier);
            $this->assertNull($nation->color_turn_bonus);
            $this->assertNull($nation->economy_context_synced_at);
        }
    }

    #[Test]
    public function it_rolls_back_all_context_updates_when_persistence_fails(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $oldTimestamp = CarbonImmutable::parse('2026-07-31 12:00:00 UTC');
        Nation::factory()->count(2)->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => 0.25,
            'color_turn_bonus' => 90,
            'economy_context_synced_at' => $oldTimestamp,
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [
                [
                    'name' => 'Unrelated',
                    'bonus' => 1,
                    'nation_id' => 999999,
                    'nation' => ['alliance_id' => 999],
                ],
            ],
            colors: [
                ['color' => 'blue', 'turn_bonus' => 125],
            ],
        );
        $nationUpsertCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$nationUpsertCount): void {
            $sql = strtolower($query->sql);

            if (! str_contains($sql, 'update "nations"') || ! str_contains($sql, '"economy_context_synced_at"')) {
                return;
            }

            $nationUpsertCount++;

            throw new RuntimeException('Forced context persistence failure.');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forced context persistence failure.');

        try {
            app(EconomyContextService::class)->refresh();
        } finally {
            $this->assertSame(1, $nationUpsertCount);
            $this->assertSame(2, Nation::query()->where('treasure_income_modifier', 0.25)->count());
            $this->assertSame(2, Nation::query()->where('color_turn_bonus', 90)->count());
            $this->assertSame(
                2,
                Nation::query()->where('economy_context_synced_at', $oldTimestamp->toDateTimeString())->count()
            );
        }
    }

    #[Test]
    public function refresh_command_reports_synchronized_skipped_and_failed_counts(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        Nation::factory()->count(2)->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [
                [
                    'name' => 'Unrelated',
                    'bonus' => 1,
                    'nation_id' => 999999,
                    'nation' => ['alliance_id' => 999],
                ],
            ],
            colors: [
                ['color' => 'blue', 'turn_bonus' => 125],
            ],
        );
        Log::spy();

        $this->artisan('economy-context:refresh')
            ->expectsOutput('Economy context refresh completed: 2 synchronized, 0 skipped, 0 failed.')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Economy context refresh completed', [
                'synchronized' => 2,
                'skipped' => 0,
                'failed' => 0,
            ]);
    }

    #[Test]
    public function refresh_command_reports_all_active_nations_as_failed_for_incomplete_context(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $oldTimestamp = CarbonImmutable::parse('2026-07-31 12:00:00 UTC');
        Nation::factory()->count(2)->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => 0.25,
            'color_turn_bonus' => 90,
            'economy_context_synced_at' => $oldTimestamp,
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [
                [
                    'name' => 'Unrelated',
                    'bonus' => 1,
                    'nation_id' => 999999,
                    'nation' => ['alliance_id' => 999],
                ],
            ],
            colors: [
                ['color' => 'red', 'turn_bonus' => 125],
            ],
        );
        Log::spy();

        $this->artisan('economy-context:refresh')
            ->expectsOutput('Economy context refresh failed: 0 synchronized, 0 skipped, 2 failed.')
            ->assertFailed();

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'Economy context refresh failed',
                \Mockery::on(fn (array $context): bool => $context['synchronized'] === 0
                    && $context['skipped'] === 0
                    && $context['failed'] === 2
                    && $context['exception'] instanceof RuntimeException)
            );
        $this->assertSame(2, Nation::query()->where('treasure_income_modifier', 0.25)->count());
        $this->assertSame(2, Nation::query()->where('color_turn_bonus', 90)->count());
        $this->assertSame(
            2,
            Nation::query()->where('economy_context_synced_at', $oldTimestamp->toDateTimeString())->count()
        );
    }

    #[Test]
    public function it_only_rewrites_nations_whose_context_changed(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $previousStamp = CarbonImmutable::parse('2026-08-01 17:00:00 UTC');
        $unchanged = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 125,
            'economy_context_synced_at' => $previousStamp,
        ]);
        $changed = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 90,
            'economy_context_synced_at' => $previousStamp,
        ]);
        $unchangedUpdatedAt = $unchanged->updated_at;
        $this->fakeEconomyContextResponses(
            treasures: [$this->unrelatedTreasure()],
            colors: [['color' => 'blue', 'turn_bonus' => 125]],
        );
        $writtenNationIds = $this->recordContextWrites();

        $count = app(EconomyContextService::class)->refresh();
        $unchanged->refresh();
        $changed->refresh();

        $this->assertSame(2, $count);
        $this->assertSame([(int) $changed->id], $writtenNationIds->all());
        $this->assertTrue($unchanged->economy_context_synced_at->equalTo($previousStamp));
        $this->assertTrue($unchanged->updated_at->equalTo($unchangedUpdatedAt));
        $this->assertSame(125, $changed->color_turn_bonus);
        $this->assertTrue($changed->economy_context_synced_at->equalTo(CarbonImmutable::now()));
    }

    #[Test]
    public function it_restamps_unchanged_context_once_the_stamp_reaches_the_restamp_age(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $justUnderRestampAge = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 125,
            'economy_context_synced_at' => now()
                ->subHours(EconomyRules::ECONOMY_CONTEXT_RESTAMP_AFTER_HOURS)
                ->addSecond(),
        ]);
        $atRestampAge = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 125,
            'economy_context_synced_at' => now()->subHours(EconomyRules::ECONOMY_CONTEXT_RESTAMP_AFTER_HOURS),
        ]);
        $this->fakeEconomyContextResponses(
            treasures: [$this->unrelatedTreasure()],
            colors: [['color' => 'blue', 'turn_bonus' => 125]],
        );
        $writtenNationIds = $this->recordContextWrites();

        $this->assertSame(2, app(EconomyContextService::class)->refresh());

        $this->assertSame([(int) $atRestampAge->id], $writtenNationIds->all());
        $this->assertTrue($atRestampAge->refresh()->economy_context_synced_at->equalTo(CarbonImmutable::now()));
        $this->assertTrue(
            $justUnderRestampAge->refresh()->economy_context_synced_at->equalTo(
                CarbonImmutable::now()->subHours(EconomyRules::ECONOMY_CONTEXT_RESTAMP_AFTER_HOURS)->addSecond()
            )
        );
    }

    #[Test]
    public function it_leaves_soft_deleted_nations_alone(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 18:00:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $oldTimestamp = CarbonImmutable::parse('2026-07-31 12:00:00 UTC');
        $active = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
        ]);
        $deleted = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'purple',
            'treasure_income_modifier' => 0.25,
            'color_turn_bonus' => 90,
            'economy_context_synced_at' => $oldTimestamp,
        ]);
        $deleted->delete();
        $this->fakeEconomyContextResponses(
            treasures: [$this->unrelatedTreasure()],
            colors: [['color' => 'blue', 'turn_bonus' => 125]],
        );
        $writtenNationIds = $this->recordContextWrites();

        $this->assertSame(1, app(EconomyContextService::class)->refresh());

        $this->assertSame([(int) $active->id], $writtenNationIds->all());
        $deleted = Nation::withTrashed()->findOrFail($deleted->id);
        $this->assertSame(90, $deleted->color_turn_bonus);
        $this->assertTrue($deleted->economy_context_synced_at->equalTo($oldTimestamp));
    }

    #[Test]
    public function hourly_refreshes_keep_unchanged_context_fresh_without_rewriting_it_every_hour(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 12:16:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $nation = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => null,
            'color_turn_bonus' => null,
            'economy_context_synced_at' => null,
        ]);
        $this->fakeEconomyContextResponsesForEveryRefresh(
            treasures: [$this->unrelatedTreasure()],
            colors: [['color' => 'blue', 'turn_bonus' => 125]],
        );
        $writtenNationIds = $this->recordContextWrites();
        $profitability = app(NationProfitabilityService::class);
        $radiationSnapshot = $this->radiationSnapshot();

        for ($hour = 0; $hour <= 12; $hour++) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 12:16:00 UTC')->addHours($hour));

            $this->assertSame(1, app(EconomyContextService::class)->refresh());
            $profitability->assertCalculationContextAvailable($nation->refresh(), $radiationSnapshot);
        }

        $this->assertSame(125, $nation->color_turn_bonus);
        $this->assertCount(7, $writtenNationIds);
        $this->assertTrue(
            $nation->economy_context_synced_at->equalTo(CarbonImmutable::parse('2026-08-02 00:16:00 UTC'))
        );
    }

    #[Test]
    public function economy_context_goes_stale_when_the_refresh_stops_running(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 12:16:00 UTC');
        config(['services.pw.api_key' => 'test-key']);
        $nation = Nation::factory()->create([
            'alliance_id' => 77,
            'alliance_position' => 'MEMBER',
            'color' => 'blue',
            'treasure_income_modifier' => null,
            'color_turn_bonus' => null,
            'economy_context_synced_at' => null,
        ]);
        $this->fakeEconomyContextResponsesForEveryRefresh(
            treasures: [$this->unrelatedTreasure()],
            colors: [['color' => 'blue', 'turn_bonus' => 125]],
        );
        $profitability = app(NationProfitabilityService::class);
        $radiationSnapshot = $this->radiationSnapshot();

        app(EconomyContextService::class)->refresh();
        CarbonImmutable::setTestNow('2026-08-01 13:16:00 UTC');
        app(EconomyContextService::class)->refresh();

        CarbonImmutable::setTestNow('2026-08-01 18:16:00 UTC');
        $profitability->assertCalculationContextAvailable($nation->refresh(), $radiationSnapshot);

        CarbonImmutable::setTestNow('2026-08-01 18:16:01 UTC');
        $this->expectException(ProfitabilityContextUnavailable::class);
        $this->expectExceptionMessage("Economy context is stale for nation {$nation->id}.");

        $profitability->assertCalculationContextAvailable($nation->refresh(), $radiationSnapshot);
    }

    /**
     * @return Collection<int, int>
     */
    private function recordContextWrites(): Collection
    {
        $writtenNationIds = collect();

        DB::listen(function (QueryExecuted $query) use ($writtenNationIds): void {
            $sql = strtolower($query->sql);

            if (! str_starts_with($sql, 'update "nations"') || ! str_contains($sql, '"economy_context_synced_at"')) {
                return;
            }

            foreach (array_slice($query->bindings, 3) as $nationId) {
                $writtenNationIds->push((int) $nationId);
            }
        });

        return $writtenNationIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function unrelatedTreasure(): array
    {
        return [
            'name' => 'Unrelated',
            'bonus' => 1,
            'nation_id' => 999999,
            'nation' => ['alliance_id' => 999],
        ];
    }

    private function radiationSnapshot(): RadiationSnapshot
    {
        return RadiationSnapshot::query()->create([
            'snapshot_at' => now(),
            'game_date' => '2126-09-21',
            'global' => 0,
            'north_america' => 0,
            'south_america' => 0,
            'europe' => 0,
            'africa' => 0,
            'asia' => 0,
            'australia' => 0,
            'antarctica' => 0,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $treasures
     * @param  list<array<string, mixed>>  $colors
     */
    private function fakeEconomyContextResponsesForEveryRefresh(array $treasures, array $colors): void
    {
        $treasures = $this->withTreasureOwners($treasures);

        Http::fake(fn (Request $request) => str_contains($request->body(), 'treasures')
            ? Http::response(['data' => ['treasures' => $treasures]])
            : Http::response(['data' => ['colors' => $colors]]));
    }

    /**
     * @param  list<array<string, mixed>>  $treasures
     * @return list<array<string, mixed>>
     */
    private function withTreasureOwners(array $treasures): array
    {
        return collect($treasures)->map(function (array $treasure): array {
            $treasure['nation'] ??= [];
            $treasure['nation']['id'] ??= $treasure['nation_id'] ?? null;

            return $treasure;
        })->all();
    }

    /**
     * @param  list<array<string, mixed>>  $treasures
     * @param  list<array<string, mixed>>  $colors
     */
    private function fakeEconomyContextResponses(array $treasures, array $colors): void
    {
        $treasures = $this->withTreasureOwners($treasures);

        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['treasures' => $treasures]])
                ->push(['data' => ['colors' => $colors]]),
        ]);
    }
}

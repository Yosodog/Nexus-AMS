<?php

namespace Tests\Feature\Services\Economy;

use App\Models\Nation;
use App\Services\Economy\EconomyContextService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

            if (! str_contains($sql, 'insert into "nations"') || ! str_contains($sql, 'on conflict')) {
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

    /**
     * @param  list<array<string, mixed>>  $treasures
     * @param  list<array<string, mixed>>  $colors
     */
    private function fakeEconomyContextResponses(array $treasures, array $colors): void
    {
        $treasures = collect($treasures)->map(function (array $treasure): array {
            $treasure['nation'] ??= [];
            $treasure['nation']['id'] ??= $treasure['nation_id'] ?? null;

            return $treasure;
        })->all();

        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['treasures' => $treasures]])
                ->push(['data' => ['colors' => $colors]]),
        ]);
    }
}

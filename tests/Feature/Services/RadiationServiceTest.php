<?php

namespace Tests\Feature\Services;

use App\Models\RadiationSnapshot;
use App\Services\GameInfoQueryService;
use App\Services\RadiationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RadiationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pw.api_key' => 'test-api-key',
            'services.pw.endpoint' => 'https://pw.test/graphql',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_game_info_query_returns_a_complete_atomic_economy_snapshot(): void
    {
        $this->fakeGameInfoResponse($this->completeGameInfo());

        $snapshot = app(GameInfoQueryService::class)->getEconomySnapshot();

        $this->assertSame([
            'game_date' => '2126-09-21',
            'global' => 12.5,
            'north_america' => 21.25,
            'south_america' => 31.5,
            'europe' => 41.75,
            'africa' => 52.0,
            'asia' => 62.25,
            'australia' => 72.5,
            'antarctica' => 82.75,
        ], $snapshot);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://pw.test/graphql?api_key=test-api-key'
                && ($request->data()['query'] ?? null) === 'query { game_info { game_date radiation { global north_america south_america europe africa asia australia antarctica } } }';
        });
        Http::assertSentCount(1);
    }

    public function test_refresh_publishes_a_complete_game_state_snapshot(): void
    {
        $snapshotAt = Carbon::parse('2026-08-02 18:00:00 UTC');
        $this->fakeGameInfoResponse($this->completeGameInfo());

        $snapshot = app(RadiationService::class)->refresh($snapshotAt);

        $this->assertNotNull($snapshot);
        $this->assertSame('2126-09-21', $snapshot->game_date?->toDateString());
        $this->assertSame($snapshotAt->toDateTimeString(), $snapshot->snapshot_at?->toDateTimeString());

        foreach (self::radiationValues() as $field => $expected) {
            $this->assertSame($expected, $snapshot->{$field});
        }

        $this->assertDatabaseHas('radiation_snapshots', [
            'id' => $snapshot->id,
            'snapshot_at' => $snapshotAt->toDateTimeString(),
        ]);
        $this->assertDatabaseCount('radiation_snapshots', 1);
    }

    /**
     * @param  array<string, mixed>  $gameInfo
     */
    #[DataProvider('invalidGameInfoProvider')]
    public function test_refresh_rejects_incomplete_or_malformed_game_state_without_publishing(array $gameInfo): void
    {
        $this->fakeGameInfoResponse($gameInfo);

        $snapshot = app(RadiationService::class)->refresh();

        $this->assertNull($snapshot);
        $this->assertDatabaseCount('radiation_snapshots', 0);
        Http::assertSentCount(1);
    }

    public function test_game_info_query_reports_invalid_input_before_it_can_be_persisted(): void
    {
        $gameInfo = $this->completeGameInfo();
        $gameInfo['radiation']['europe'] = 'not-a-number';
        $this->fakeGameInfoResponse($gameInfo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('omitted valid europe radiation');

        app(GameInfoQueryService::class)->getEconomySnapshot();
    }

    public function test_latest_ignores_newer_legacy_rows_without_a_game_date(): void
    {
        $eligible = $this->createSnapshot([
            'snapshot_at' => '2026-08-02 17:00:00',
            'game_date' => '2126-09-20',
        ]);
        $legacy = $this->createSnapshot([
            'snapshot_at' => '2026-08-02 18:00:00',
            'game_date' => null,
        ]);
        Http::fake();

        $service = app(RadiationService::class);

        $this->assertTrue($eligible->is($service->latest()));
        $this->assertTrue($eligible->is($service->latestOrRefresh(refreshIfStale: false)));
        $this->assertDatabaseHas('radiation_snapshots', ['id' => $legacy->id, 'game_date' => null]);
        Http::assertNothingSent();
    }

    public function test_stale_automatic_selection_refreshes_while_the_pinned_snapshot_remains_reproducible(): void
    {
        Carbon::setTestNow('2026-08-02 18:00:00 UTC');
        $pinnedSnapshot = $this->createSnapshot([
            'snapshot_at' => now()->subHours(4),
            'game_date' => '2126-09-20',
            'global' => 7.5,
        ]);
        $pinnedSnapshotId = $pinnedSnapshot->id;
        $pinnedSnapshotAt = $pinnedSnapshot->snapshot_at?->toDateTimeString();
        $this->fakeGameInfoResponse($this->completeGameInfo());

        $automaticallySelected = app(RadiationService::class)->latestOrRefresh();

        $this->assertNotNull($automaticallySelected);
        $this->assertNotSame($pinnedSnapshotId, $automaticallySelected->id);
        $this->assertSame('2126-09-21', $automaticallySelected->game_date?->toDateString());
        $this->assertSame(now()->toDateTimeString(), $automaticallySelected->snapshot_at?->toDateTimeString());
        $this->assertDatabaseCount('radiation_snapshots', 2);

        $reloadedPinnedSnapshot = RadiationSnapshot::query()->findOrFail($pinnedSnapshotId);

        $this->assertSame('2126-09-20', $reloadedPinnedSnapshot->game_date?->toDateString());
        $this->assertSame($pinnedSnapshotAt, $reloadedPinnedSnapshot->snapshot_at?->toDateTimeString());
        $this->assertSame(7.5, $reloadedPinnedSnapshot->global);
        Http::assertSentCount(1);
    }

    public function test_snapshot_at_the_three_hour_boundary_is_selected_without_refreshing(): void
    {
        Carbon::setTestNow('2026-08-02 18:00:00 UTC');
        $snapshot = $this->createSnapshot([
            'snapshot_at' => now()->subHours(3),
            'game_date' => '2126-09-21',
        ]);
        Http::fake();

        $selected = app(RadiationService::class)->latestOrRefresh();

        $this->assertTrue($snapshot->is($selected));
        Http::assertNothingSent();
    }

    public function test_refresh_rejects_a_regressed_game_date_without_publishing(): void
    {
        $existing = $this->createSnapshot([
            'game_date' => '2126-09-22',
        ]);
        $this->fakeGameInfoResponse($this->completeGameInfo());

        $this->assertNull(app(RadiationService::class)->refresh());
        $this->assertDatabaseCount('radiation_snapshots', 1);
        $this->assertSame('2126-09-22', $existing->fresh()->game_date?->toDateString());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidGameInfoProvider(): iterable
    {
        $radiation = self::radiationValues();

        yield 'missing game date' => [[
            'radiation' => $radiation,
        ]];

        yield 'malformed game date' => [[
            'game_date' => 'not-a-date',
            'radiation' => $radiation,
        ]];

        yield 'relative game date' => [[
            'game_date' => 'tomorrow',
            'radiation' => $radiation,
        ]];

        yield 'impossible game date' => [[
            'game_date' => '2126-02-30',
            'radiation' => $radiation,
        ]];

        yield 'impossible timestamp date' => [[
            'game_date' => '2126-02-30T00:00:00+00:00',
            'radiation' => $radiation,
        ]];

        yield 'invalid timestamp time' => [[
            'game_date' => '2126-09-21T25:00:00+00:00',
            'radiation' => $radiation,
        ]];

        yield 'missing radiation object' => [[
            'game_date' => '2126-09-21',
        ]];

        $missingField = $radiation;
        unset($missingField['antarctica']);

        yield 'missing radiation field' => [[
            'game_date' => '2126-09-21',
            'radiation' => $missingField,
        ]];

        yield 'malformed radiation field' => [[
            'game_date' => '2126-09-21',
            'radiation' => [...$radiation, 'europe' => 'not-a-number'],
        ]];

        yield 'negative radiation field' => [[
            'game_date' => '2126-09-21',
            'radiation' => [...$radiation, 'global' => -0.01],
        ]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSnapshot(array $overrides = []): RadiationSnapshot
    {
        return RadiationSnapshot::query()->create([
            'snapshot_at' => now(),
            'game_date' => '2126-09-21',
            ...self::radiationValues(),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeGameInfo(): array
    {
        return [
            'game_date' => '2126-09-21T00:00:00+00:00',
            'radiation' => self::radiationValues(),
        ];
    }

    /**
     * @param  array<string, mixed>  $gameInfo
     */
    private function fakeGameInfoResponse(array $gameInfo): void
    {
        Http::fake([
            'https://pw.test/graphql*' => Http::response([
                'data' => [
                    'game_info' => $gameInfo,
                ],
            ]),
        ]);
    }

    /**
     * @return array<string, float>
     */
    private static function radiationValues(): array
    {
        return [
            'global' => 12.5,
            'north_america' => 21.25,
            'south_america' => 31.5,
            'europe' => 41.75,
            'africa' => 52.0,
            'asia' => 62.25,
            'australia' => 72.5,
            'antarctica' => 82.75,
        ];
    }
}

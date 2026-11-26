<?php

namespace Tests\Feature\API;

use App\Events\NationAllianceChanged;
use App\GraphQL\Models\Nation as GraphQLNation;
use App\Jobs\CreateNationJob;
use App\Jobs\RefreshNationProfitabilitySnapshotJob;
use App\Jobs\UpdateAllianceJob;
use App\Jobs\UpdateCityJob;
use App\Jobs\UpdateNationJob;
use App\Jobs\UpdateWarJob;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationProfitabilitySnapshot;
use App\Models\RaidTargetProfile;
use App\Services\AllianceMembershipService;
use App\Services\BeigeAlertService;
use App\Services\Economy\EconomyRules;
use App\Services\SubscriptionEventProcessor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SubscriptionBulkChunkingTest extends TestCase
{
    use RefreshDatabase;

    private const MEMBER_ALLIANCE_ID = 4100;

    private const OTHER_ALLIANCE_ID = 4200;

    /** @var list<string>|null */
    private ?array $capturedQueries = null;

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function (QueryExecuted $query): void {
            if ($this->capturedQueries !== null) {
                $this->capturedQueries[] = $query->sql;
            }
        });

        config()->set('services.pw.alliance_id', self::MEMBER_ALLIANCE_ID);
        app(AllianceMembershipService::class)->refresh();

        Alliance::factory()->create(['id' => self::MEMBER_ALLIANCE_ID]);
        Alliance::factory()->create(['id' => self::OTHER_ALLIANCE_ID]);
    }

    #[DataProvider('bulkEventProvider')]
    public function test_bulk_messages_are_split_into_bounded_jobs_that_carry_every_record_once(
        string $model,
        string $event,
        string $jobClass,
        string $payloadProperty,
        array $recordFields,
    ): void {
        Queue::fake();
        config()->set('subscriptions.ingestion.bulk_job_chunk_size', 7);

        $records = array_map(
            fn (int $id): array => ['id' => $id, ...$recordFields],
            range(1001, 1050),
        );

        app(SubscriptionEventProcessor::class)->process($model, $event, $records);

        $jobs = Queue::pushed($jobClass);
        $this->assertCount((int) ceil(50 / 7), $jobs);

        $chunks = $jobs->map(fn (object $job): array => $job->{$payloadProperty})->values();
        $chunks->each(fn (array $chunk) => $this->assertLessThanOrEqual(7, count($chunk)));
        $this->assertSame($records, $chunks->flatten(1)->all());
    }

    public function test_default_chunk_size_splits_a_turn_change_burst(): void
    {
        Queue::fake();

        $records = array_map(fn (int $id): array => ['id' => $id, 'score' => 10.5], range(1, 415));

        app(SubscriptionEventProcessor::class)->process('nation', 'update', $records);

        $chunkSize = (int) config('subscriptions.ingestion.bulk_job_chunk_size');
        $this->assertGreaterThanOrEqual(25, $chunkSize);
        $this->assertLessThanOrEqual(50, $chunkSize);
        Queue::assertPushed(UpdateNationJob::class, (int) ceil(415 / $chunkSize));
    }

    public function test_nation_chunk_lookups_do_not_grow_with_the_number_of_records(): void
    {
        $small = $this->countLookupQueries(3);
        $large = $this->countLookupQueries(20);

        $this->assertSame(1, $small['nation_selects']);
        $this->assertSame(1, $small['military_selects']);
        $this->assertSame(1, $small['snapshot_deletes']);
        $this->assertSame(0, $small['setting_selects']);
        $this->assertSame($small, $large);
    }

    public function test_alliance_change_event_fires_only_for_stored_nations_that_changed_alliance(): void
    {
        Queue::fake();
        Event::fake([NationAllianceChanged::class]);

        $mover = $this->storedNation(['alliance_id' => self::MEMBER_ALLIANCE_ID, 'alliance_position' => 'MEMBER']);
        $stayer = $this->storedNation(['alliance_id' => self::MEMBER_ALLIANCE_ID]);

        $this->runNationJob([
            ['id' => $mover->id, 'alliance_id' => self::OTHER_ALLIANCE_ID, 'alliance_position' => 'APPLICANT'],
            ['id' => $stayer->id, 'score' => 1234.5],
            $this->newNationRecord(990_001, ['nation_name' => 'Brand New', 'alliance_id' => self::OTHER_ALLIANCE_ID]),
        ]);

        Event::assertDispatchedTimes(NationAllianceChanged::class, 1);
        Event::assertDispatched(
            NationAllianceChanged::class,
            fn (NationAllianceChanged $event): bool => $event->nation->is($mover)
                && $event->oldAllianceId === self::MEMBER_ALLIANCE_ID
                && $event->oldAlliancePosition === 'MEMBER'
                && $event->newAllianceId === self::OTHER_ALLIANCE_ID
                && $event->newAlliancePosition === 'APPLICANT'
        );
        $this->assertDatabaseHas('nations', ['id' => 990_001, 'nation_name' => 'Brand New']);
    }

    public function test_beige_early_exit_check_receives_stored_and_new_turns_for_stored_nations_only(): void
    {
        Queue::fake();

        $leaving = $this->storedNation(['beige_turns' => 5, 'alliance_id' => self::OTHER_ALLIANCE_ID]);
        $staying = $this->storedNation(['beige_turns' => 3]);

        $beige = Mockery::mock(BeigeAlertService::class);
        $beige->shouldReceive('maybeDispatchEarlyExitAlert')
            ->once()
            ->with($leaving->id, self::OTHER_ALLIANCE_ID, 5, 0);
        $beige->shouldReceive('maybeDispatchEarlyExitAlert')
            ->once()
            ->with($staying->id, $staying->alliance_id, 3, 2);
        $this->app->instance(BeigeAlertService::class, $beige);

        $this->runNationJob([
            ['id' => $leaving->id, 'beige_turns' => 0],
            ['id' => $staying->id, 'beige_turns' => 2],
            $this->newNationRecord(990_002, ['beige_turns' => 0]),
        ]);
    }

    public function test_beige_settings_are_not_read_unless_the_nation_left_beige_early(): void
    {
        $queries = $this->recordQueries(function (): void {
            app(BeigeAlertService::class)->maybeDispatchEarlyExitAlert(1, self::MEMBER_ALLIANCE_ID, 3, 2);
            app(BeigeAlertService::class)->maybeDispatchEarlyExitAlert(1, self::MEMBER_ALLIANCE_ID, 1, 0);
        });

        $this->assertSame([], $queries);

        $queries = $this->recordQueries(function (): void {
            app(BeigeAlertService::class)->maybeDispatchEarlyExitAlert(1, self::MEMBER_ALLIANCE_ID, 3, 0);
        });

        $this->assertNotSame([], $queries);
    }

    public function test_snapshots_are_refreshed_for_eligible_nations_and_deleted_for_the_rest(): void
    {
        Queue::fake([RefreshNationProfitabilitySnapshotJob::class]);
        Event::fake([NationAllianceChanged::class]);

        $member = $this->storedNation(['alliance_id' => self::MEMBER_ALLIANCE_ID]);
        $leaver = $this->storedNation(['alliance_id' => self::MEMBER_ALLIANCE_ID]);
        $outsider = $this->storedNation(['alliance_id' => self::OTHER_ALLIANCE_ID]);
        $untouched = $this->storedNation(['alliance_id' => self::OTHER_ALLIANCE_ID]);

        foreach ([$member, $leaver, $outsider, $untouched] as $nation) {
            $this->storeSnapshot($nation);
        }

        $this->runNationJob([
            ['id' => $member->id, 'score' => 2000.0],
            ['id' => $leaver->id, 'alliance_id' => self::OTHER_ALLIANCE_ID],
            ['id' => $outsider->id, 'score' => 10.0],
        ]);

        Queue::assertPushed(RefreshNationProfitabilitySnapshotJob::class, 1);
        $this->assertDatabaseHas('nation_profitability_snapshots', ['nation_id' => $member->id]);
        $this->assertDatabaseMissing('nation_profitability_snapshots', ['nation_id' => $leaver->id]);
        $this->assertDatabaseMissing('nation_profitability_snapshots', ['nation_id' => $outsider->id]);
        $this->assertDatabaseHas('nation_profitability_snapshots', ['nation_id' => $untouched->id]);
    }

    public function test_soft_deleted_nations_in_a_chunk_are_restored_and_updated(): void
    {
        Queue::fake();

        $returning = $this->storedNation(['nation_name' => 'Gone']);
        $returning->delete();

        $this->runNationJob([['id' => $returning->id, 'nation_name' => 'Back Again']]);

        $restored = Nation::query()->find($returning->id);
        $this->assertNotNull($restored);
        $this->assertSame('Back Again', $restored->nation_name);
        $this->assertSame(1, Nation::withTrashed()->whereKey($returning->id)->count());
    }

    public function test_military_is_updated_from_the_preloaded_row_or_created_when_missing(): void
    {
        Queue::fake();

        $armed = $this->storedNation();
        NationMilitary::query()->create(['nation_id' => $armed->id, 'soldiers' => 100, 'tanks' => 7]);
        $unarmed = $this->storedNation();

        $this->runNationJob([
            ['id' => $armed->id, 'soldiers' => 250],
            ['id' => $unarmed->id, 'soldiers' => 40],
            ['id' => $armed->id, 'tanks' => 9],
        ]);

        $this->assertSame(1, NationMilitary::query()->where('nation_id', $armed->id)->count());
        $this->assertDatabaseHas('nation_military', ['nation_id' => $armed->id, 'soldiers' => 250, 'tanks' => 9]);
        $this->assertDatabaseHas('nation_military', ['nation_id' => $unarmed->id, 'soldiers' => 40]);
    }

    public function test_repeated_new_nation_in_one_chunk_is_created_once_and_then_updated(): void
    {
        Queue::fake();
        Event::fake([NationAllianceChanged::class]);

        $this->runNationJob([
            $this->newNationRecord(990_003, ['alliance_id' => self::OTHER_ALLIANCE_ID]),
            ['id' => 990_003, 'alliance_id' => self::MEMBER_ALLIANCE_ID],
        ]);

        $this->assertSame(1, Nation::withTrashed()->whereKey(990_003)->count());
        $this->assertDatabaseHas('nations', ['id' => 990_003, 'alliance_id' => self::MEMBER_ALLIANCE_ID]);
        Event::assertDispatchedTimes(NationAllianceChanged::class, 1);
    }

    public function test_a_failing_record_keeps_the_dirty_marks_and_snapshot_deletes_of_earlier_records(): void
    {
        Queue::fake();

        $first = $this->storedNation(['alliance_id' => self::OTHER_ALLIANCE_ID]);
        $second = $this->storedNation(['alliance_id' => self::OTHER_ALLIANCE_ID]);
        $failing = $this->storedNation(['alliance_id' => self::OTHER_ALLIANCE_ID]);
        $this->storeSnapshot($first);
        $this->storeSnapshot($second);

        $beige = Mockery::mock(BeigeAlertService::class);
        $beige->shouldReceive('maybeDispatchEarlyExitAlert')
            ->andReturnUsing(function (int $nationId) use ($failing): void {
                if ($nationId === $failing->id) {
                    throw new RuntimeException('Simulated failure.');
                }
            });
        $this->app->instance(BeigeAlertService::class, $beige);

        try {
            $this->runNationJob([
                ['id' => $first->id, 'score' => 11.0],
                ['id' => $second->id, 'score' => 12.0],
                ['id' => $failing->id, 'score' => 13.0],
            ]);
            $this->fail('The failing record did not throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure.', $exception->getMessage());
        }

        $dirty = RaidTargetProfile::query()->whereNotNull('dirty_at')->pluck('nation_id')->map(fn ($id): int => (int) $id);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $dirty->all());
        $this->assertDatabaseMissing('nation_profitability_snapshots', ['nation_id' => $first->id]);
        $this->assertDatabaseMissing('nation_profitability_snapshots', ['nation_id' => $second->id]);
    }

    public function test_job_payloads_serialized_by_the_previous_release_still_run(): void
    {
        Queue::fake();
        $this->storedNation(['id' => 501, 'nation_name' => 'Before A']);
        $this->storedNation(['id' => 502, 'nation_name' => 'Before B']);
        Alliance::factory()->create(['id' => 801, 'name' => 'Before Alliance']);

        $nationJob = unserialize('O:24:"App\Jobs\UpdateNationJob":1:{s:11:"nationsData";a:2:{i:0;a:2:{s:2:"id";i:501;s:11:"nation_name";s:5:"Old A";}i:1;a:2:{s:2:"id";i:502;s:11:"nation_name";s:5:"Old B";}}}');
        $allianceJob = unserialize('O:26:"App\Jobs\UpdateAllianceJob":1:{s:13:"alliancesData";a:1:{i:0;a:2:{s:2:"id";i:801;s:4:"name";s:12:"Old Alliance";}}}');
        $warJob = unserialize('O:21:"App\Jobs\UpdateWarJob":1:{s:8:"warsData";a:1:{i:0;a:1:{s:2:"id";i:701;}}}');
        $cityJob = unserialize('O:22:"App\Jobs\UpdateCityJob":1:{s:10:"citiesData";a:1:{i:0;a:3:{s:2:"id";i:601;s:9:"nation_id";i:501;s:4:"name";s:8:"Old City";}}}');
        $createJob = unserialize('O:24:"App\Jobs\CreateNationJob":1:{s:11:"nationsData";a:1:{i:0;a:1:{s:2:"id";i:503;}}}');

        $this->assertSame([['id' => 601, 'nation_id' => 501, 'name' => 'Old City']], $cityJob->citiesData);
        $this->assertSame([['id' => 503]], $createJob->nationsData);

        foreach ([$nationJob, $allianceJob, $warJob, $cityJob, $createJob] as $job) {
            $this->assertSame(3, $job->tries);
            $this->assertSame([10, 30], $job->backoff);
            $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $job->timeout);
        }

        app()->call([$nationJob, 'handle']);
        app()->call([$allianceJob, 'handle']);
        app()->call([$warJob, 'handle']);

        $this->assertDatabaseHas('nations', ['id' => 501, 'nation_name' => 'Old A']);
        $this->assertDatabaseHas('nations', ['id' => 502, 'nation_name' => 'Old B']);
        $this->assertDatabaseHas('alliances', ['id' => 801, 'name' => 'Old Alliance']);
    }

    public function test_preloaded_nation_must_match_the_payload(): void
    {
        $stored = $this->storedNation();
        $payload = new GraphQLNation;
        $payload->buildWithJSON((object) ['id' => $stored->id + 1, 'nation_name' => 'Mismatch']);

        $this->expectException(InvalidArgumentException::class);

        Nation::updateFromAPI($payload, $stored);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: class-string, 3: string, 4: array<string, mixed>}>
     */
    public static function bulkEventProvider(): iterable
    {
        yield 'nation create' => ['nation', 'create', CreateNationJob::class, 'nationsData', []];
        yield 'nation update' => ['nation', 'update', UpdateNationJob::class, 'nationsData', ['score' => 10.5]];
        yield 'alliance update' => ['alliance', 'update', UpdateAllianceJob::class, 'alliancesData', ['name' => 'Bulk']];
        yield 'city create' => ['city', 'create', UpdateCityJob::class, 'citiesData', ['nation_id' => 77]];
        yield 'city update' => ['city', 'update', UpdateCityJob::class, 'citiesData', ['nation_id' => 77]];
        yield 'war update' => ['war', 'update', UpdateWarJob::class, 'warsData', ['turns_left' => 4]];
    }

    /**
     * @return array{nation_selects: int, military_selects: int, snapshot_deletes: int, setting_selects: int}
     */
    private function countLookupQueries(int $recordCount): array
    {
        Queue::fake();

        $records = [];

        for ($index = 0; $index < $recordCount; $index++) {
            $nation = $this->storedNation(['alliance_id' => self::OTHER_ALLIANCE_ID, 'beige_turns' => 2]);
            NationMilitary::query()->create(['nation_id' => $nation->id, 'soldiers' => 10]);
            $records[] = ['id' => $nation->id, 'score' => 99.5 + $index, 'soldiers' => 20 + $index, 'beige_turns' => 1];
        }

        $queries = $this->recordQueries(fn () => $this->runNationJob($records));

        $count = fn (string $pattern): int => count(array_filter(
            $queries,
            fn (string $sql): bool => preg_match($pattern, $sql) === 1,
        ));

        return [
            'nation_selects' => $count('/^select .* from "nations"/i'),
            'military_selects' => $count('/^select .* from "nation_military"/i'),
            'snapshot_deletes' => $count('/^delete from "nation_profitability_snapshots"/i'),
            'setting_selects' => $count('/from "settings"/i'),
        ];
    }

    /**
     * @return list<string>
     */
    private function recordQueries(callable $callback): array
    {
        $this->capturedQueries = [];

        try {
            $callback();

            return $this->capturedQueries;
        } finally {
            $this->capturedQueries = null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function runNationJob(array $records): void
    {
        app()->call([new UpdateNationJob($records), 'handle']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storedNation(array $attributes = []): Nation
    {
        return Nation::factory()->create([
            'alliance_id' => self::MEMBER_ALLIANCE_ID,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function newNationRecord(int $id, array $fields = []): array
    {
        return [
            'id' => $id,
            'nation_name' => "Nation {$id}",
            'leader_name' => "Leader {$id}",
            'continent' => 'NA',
            'color' => 'blue',
            ...$fields,
        ];
    }

    private function storeSnapshot(Nation $nation): void
    {
        NationProfitabilitySnapshot::query()->create([
            'nation_id' => $nation->id,
            'alliance_id' => $nation->alliance_id,
            'model_version' => EconomyRules::MODEL_VERSION,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'cities' => $nation->num_cities,
            'resource_profit_per_day' => array_fill_keys(EconomyRules::RESOURCE_KEYS, 0.0),
            'calculated_at' => now()->subHour(),
        ]);
    }
}

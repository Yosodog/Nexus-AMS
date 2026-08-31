<?php

namespace Tests\Feature\API;

use App\Jobs\CreateNationJob;
use App\Jobs\CreateWarAttackJob;
use App\Jobs\DeleteNationAccountJob;
use App\Jobs\UpdateAllianceJob;
use App\Jobs\UpdateCityJob;
use App\Jobs\UpdateNationJob;
use App\Jobs\UpdateWarJob;
use App\Jobs\UpsertNationAccountJob;
use App\Services\SubscriptionEventProcessor;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionEventProcessorTest extends TestCase
{
    private string $quarantineFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quarantineFile = sys_get_temp_dir().'/nexus-subscription-records-'.bin2hex(random_bytes(6)).'.jsonl';
        config()->set('subscriptions.ingestion.quarantine_file', $this->quarantineFile);
    }

    protected function tearDown(): void
    {
        if (is_file($this->quarantineFile)) {
            unlink($this->quarantineFile);
        }

        parent::tearDown();
    }

    #[DataProvider('queuedEventProvider')]
    public function test_it_routes_single_and_bulk_payloads_to_existing_jobs(
        string $model,
        string $event,
        string $jobClass,
        string $payloadProperty,
        array $eventFields = [],
    ): void {
        Queue::fake();

        $processor = app(SubscriptionEventProcessor::class);
        $processor->process($model, $event, ['id' => 101, ...$eventFields]);
        $processor->process($model, $event, [
            ['id' => 202, ...$eventFields],
            ['id' => 303, ...$eventFields],
        ]);

        Queue::assertPushed($jobClass, 2);
        Queue::assertPushed($jobClass, fn (object $job): bool => $job->{$payloadProperty} === [
            ['id' => 101, ...$eventFields],
        ]);
        Queue::assertPushed($jobClass, fn (object $job): bool => $job->{$payloadProperty} === [
            ['id' => 202, ...$eventFields],
            ['id' => 303, ...$eventFields],
        ]);
    }

    public function test_it_rejects_unsupported_or_malformed_messages(): void
    {
        $processor = app(SubscriptionEventProcessor::class);

        try {
            $processor->process('nation', 'snapshot', ['id' => 1]);
            $this->fail('Unsupported event did not throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unsupported subscription event', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $processor->process('nation', 'update', [['nation_name' => 'Missing ID']]);
    }

    public function test_it_quarantines_invalid_records_and_dispatches_valid_siblings(): void
    {
        Queue::fake();

        app(SubscriptionEventProcessor::class)->process('war', 'update', [
            ['id' => ['poison'], 'turns_left' => 3],
            ['id' => 201, 'att_points' => ['poison']],
            ['id' => '202', 'turns_left' => '4'],
        ]);

        Queue::assertPushed(UpdateWarJob::class, fn (UpdateWarJob $job): bool => $job->warsData === [[
            'id' => 202,
            'turns_left' => 4,
        ]]);
        $this->assertFileExists($this->quarantineFile);
        $this->assertStringContainsString('validation_failed', file_get_contents($this->quarantineFile));
    }

    public function test_war_create_requires_positive_attacker_and_defender_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains no valid records');

        app(SubscriptionEventProcessor::class)->process('war', 'create', [
            'id' => 901,
            'att_id' => 1001,
        ]);
    }

    public function test_nation_update_accepts_no_alliance_position_sentinel(): void
    {
        Queue::fake();

        app(SubscriptionEventProcessor::class)->process('nation', 'update', [
            'id' => 701,
            'alliance_id' => null,
            'alliance_position_id' => 0,
            'alliance_position' => 'NOALLIANCE',
        ]);

        Queue::assertPushed(UpdateNationJob::class, fn (UpdateNationJob $job): bool => $job->nationsData === [[
            'id' => 701,
            'alliance_id' => null,
            'alliance_position_id' => 0,
            'alliance_position' => 'NOALLIANCE',
        ]]);
        $this->assertFileDoesNotExist($this->quarantineFile);
    }

    public function test_pw_sentinels_are_normalized_before_jobs_are_dispatched(): void
    {
        Queue::fake();

        $processor = app(SubscriptionEventProcessor::class);
        $processor->process('nation', 'update', [
            'id' => '701',
            'alliance_id' => '0',
        ]);
        $processor->process('city', 'update', [
            'id' => '801',
            'nation_id' => '701',
            'nuke_date' => '0000-00-00',
        ]);
        $processor->process('war', 'update', [
            'id' => '901',
            'att_id' => '701',
            'def_id' => '702',
            'att_alliance_id' => '0',
            'def_alliance_id' => '9001',
            'winner_id' => '0',
            'ground_control' => '0',
            'air_superiority' => '0',
            'naval_blockade' => '0',
        ]);
        $processor->process('warattack', 'create', [
            'id' => '1001',
            'att_id' => '701',
            'def_id' => '702',
            'war_id' => '901',
            'type' => 'VICTORY',
            'city_id' => '0',
        ]);

        Queue::assertPushed(UpdateNationJob::class, fn (UpdateNationJob $job): bool => $job->nationsData === [[
            'id' => 701,
            'alliance_id' => null,
        ]]);
        Queue::assertPushed(UpdateCityJob::class, fn (UpdateCityJob $job): bool => $job->citiesData === [[
            'id' => 801,
            'nation_id' => 701,
            'nuke_date' => null,
        ]]);
        Queue::assertPushed(UpdateWarJob::class, fn (UpdateWarJob $job): bool => $job->warsData === [[
            'id' => 901,
            'att_id' => 701,
            'def_id' => 702,
            'att_alliance_id' => null,
            'def_alliance_id' => 9001,
            'winner_id' => null,
            'ground_control' => null,
            'air_superiority' => null,
            'naval_blockade' => null,
        ]]);
        Queue::assertPushed(CreateWarAttackJob::class, fn (CreateWarAttackJob $job): bool => $job->warAttacks === [[
            'id' => 1001,
            'att_id' => 701,
            'def_id' => 702,
            'war_id' => 901,
            'type' => 'VICTORY',
            'city_id' => null,
        ]]);
    }

    public function test_mixed_war_batch_dispatches_normalized_valid_records_and_quarantines_invalid_records(): void
    {
        Queue::fake();

        app(SubscriptionEventProcessor::class)->process('war', 'update', [
            ['id' => 901, 'att_alliance_id' => 0, 'winner_id' => 0],
            ['id' => 902, 'def_alliance_id' => -1],
        ]);

        Queue::assertPushed(UpdateWarJob::class, fn (UpdateWarJob $job): bool => $job->warsData === [[
            'id' => 901,
            'att_alliance_id' => null,
            'winner_id' => null,
        ]]);
        $this->assertFileExists($this->quarantineFile);
        $this->assertStringContainsString('validation_failed', file_get_contents($this->quarantineFile));
    }

    public function test_it_accepts_an_empty_batch_without_dispatching_work(): void
    {
        Queue::fake();

        app(SubscriptionEventProcessor::class)->process('nation', 'update', []);

        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: class-string, 3: string, 4?: array<string, mixed>}>
     */
    public static function queuedEventProvider(): iterable
    {
        yield 'nation create' => ['nation', 'create', CreateNationJob::class, 'nationsData'];
        yield 'nation update' => ['nation', 'update', UpdateNationJob::class, 'nationsData'];
        yield 'alliance update' => ['alliance', 'update', UpdateAllianceJob::class, 'alliancesData'];
        yield 'city create' => ['city', 'create', UpdateCityJob::class, 'citiesData'];
        yield 'city update' => ['city', 'update', UpdateCityJob::class, 'citiesData'];
        yield 'war update' => ['war', 'update', UpdateWarJob::class, 'warsData'];
        yield 'war attack create' => [
            'warattack',
            'create',
            CreateWarAttackJob::class,
            'warAttacks',
            ['att_id' => 1001, 'def_id' => 2002, 'war_id' => 901, 'type' => 'GROUND'],
        ];
        yield 'account create' => ['account', 'create', UpsertNationAccountJob::class, 'accounts'];
        yield 'account update' => ['account', 'update', UpsertNationAccountJob::class, 'accounts'];
        yield 'account delete' => ['account', 'delete', DeleteNationAccountJob::class, 'accounts'];
    }
}

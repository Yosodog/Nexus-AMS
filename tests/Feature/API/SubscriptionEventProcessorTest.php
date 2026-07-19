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
    #[DataProvider('queuedEventProvider')]
    public function test_it_routes_single_and_bulk_payloads_to_existing_jobs(
        string $model,
        string $event,
        string $jobClass,
        string $payloadProperty
    ): void {
        Queue::fake();

        $processor = app(SubscriptionEventProcessor::class);
        $processor->process($model, $event, ['id' => 101]);
        $processor->process($model, $event, [['id' => 202], ['id' => 303]]);

        Queue::assertPushed($jobClass, 2);
        Queue::assertPushed($jobClass, fn (object $job): bool => $job->{$payloadProperty} === [['id' => 101]]);
        Queue::assertPushed($jobClass, fn (object $job): bool => $job->{$payloadProperty} === [
            ['id' => 202],
            ['id' => 303],
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

    public function test_it_accepts_an_empty_batch_without_dispatching_work(): void
    {
        Queue::fake();

        app(SubscriptionEventProcessor::class)->process('nation', 'update', []);

        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{string, string, class-string, string}>
     */
    public static function queuedEventProvider(): iterable
    {
        yield 'nation create' => ['nation', 'create', CreateNationJob::class, 'nationsData'];
        yield 'nation update' => ['nation', 'update', UpdateNationJob::class, 'nationsData'];
        yield 'alliance update' => ['alliance', 'update', UpdateAllianceJob::class, 'alliancesData'];
        yield 'city create' => ['city', 'create', UpdateCityJob::class, 'citiesData'];
        yield 'city update' => ['city', 'update', UpdateCityJob::class, 'citiesData'];
        yield 'war update' => ['war', 'update', UpdateWarJob::class, 'warsData'];
        yield 'war attack create' => ['warattack', 'create', CreateWarAttackJob::class, 'warAttacks'];
        yield 'account create' => ['account', 'create', UpsertNationAccountJob::class, 'accounts'];
        yield 'account update' => ['account', 'update', UpsertNationAccountJob::class, 'accounts'];
        yield 'account delete' => ['account', 'delete', DeleteNationAccountJob::class, 'accounts'];
    }
}

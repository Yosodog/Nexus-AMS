<?php

namespace Tests\Feature\Scheduling;

use App\Enums\ScheduledTaskRunStatus;
use App\Jobs\ReconcileMilcomLifecycleJob;
use App\Models\ScheduledTaskRun;
use App\Services\Scheduling\ScheduledTaskFreshnessService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\FeatureTestCase;

class ScheduledTaskLifecycleTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_success_records_timestamps_duration_and_one_correlation_id(): void
    {
        Carbon::setTestNow('2026-08-06 12:00:05');
        $task = $this->scheduledCommand('pw:health-check --token=top-secret');

        event(new ScheduledTaskStarting($task));

        $run = ScheduledTaskRun::query()->sole();
        $correlationId = $run->correlation_id;

        $this->assertSame(ScheduledTaskRunStatus::Running, $run->status);
        $this->assertSame('artisan:pw:health-check', $run->task_identifier);
        $this->assertTrue(Str::isUuid($correlationId));
        $this->assertNotSame('', $run->hostname);
        $this->assertSame('2026-08-06 12:00:00', $run->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-06 12:00:05', $run->started_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-08-06 12:00:07');
        $task->exitCode = 0;
        event(new ScheduledTaskFinished($task, 1.234));

        $run->refresh();

        $this->assertSame(ScheduledTaskRunStatus::Success, $run->status);
        $this->assertSame(1234, $run->duration_ms);
        $this->assertSame(0, $run->exit_code);
        $this->assertSame($correlationId, $run->correlation_id);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, ScheduledTaskRun::query()->count());
        $this->assertStringNotContainsString('top-secret', $this->serializedRuns());
    }

    public function test_failure_records_only_non_secret_failure_metadata(): void
    {
        Carbon::setTestNow('2026-08-06 12:00:00');
        $task = $this->scheduledCommand('sync:wars --password=provider-secret');

        event(new ScheduledTaskStarting($task));

        Carbon::setTestNow('2026-08-06 12:00:03');
        $task->exitCode = 17;
        event(new ScheduledTaskFinished($task, 3.0));

        $this->assertSame(
            ScheduledTaskRunStatus::Running,
            ScheduledTaskRun::query()->sole()->status,
        );

        event(new ScheduledTaskFailed(
            $task,
            new RuntimeException('provider-secret must never be persisted'),
        ));

        $run = ScheduledTaskRun::query()->sole();

        $this->assertSame(ScheduledTaskRunStatus::Failure, $run->status);
        $this->assertSame(17, $run->exit_code);
        $this->assertSame(RuntimeException::class, $run->exception_class);
        $this->assertSame(3000, $run->duration_ms);
        $this->assertStringNotContainsString('provider-secret', $this->serializedRuns());
    }

    public function test_generic_skip_is_not_mislabeled_as_an_overlap(): void
    {
        $task = $this->scheduledCommand('audits:run')->withoutOverlapping();

        event(new ScheduledTaskSkipped($task));

        $run = ScheduledTaskRun::query()->sole();

        $this->assertSame(ScheduledTaskRunStatus::Skipped, $run->status);
        $this->assertNull($run->started_at);
        $this->assertNull($run->duration_ms);
    }

    public function test_framework_overlap_signal_records_an_overlap_without_a_false_start(): void
    {
        $task = $this->scheduledCommand('taxes:collect')->withoutOverlapping();

        event(new ScheduledTaskStarting($task));
        $task->exitCode = 0;
        $task->skippedBecauseOverlapping = true;
        event(new ScheduledTaskFinished($task, 0.0));

        $run = ScheduledTaskRun::query()->sole();

        $this->assertSame(ScheduledTaskRunStatus::Overlap, $run->status);
        $this->assertNull($run->started_at);
        $this->assertSame(0, $run->duration_ms);
        $this->assertNull($run->exit_code);
    }

    public function test_background_completion_updates_the_original_running_record(): void
    {
        Carbon::setTestNow('2026-08-06 12:00:00');
        $task = $this->scheduledCommand('backup:run')->runInBackground();

        event(new ScheduledTaskStarting($task));
        $correlationId = ScheduledTaskRun::query()->sole()->correlation_id;

        Carbon::setTestNow('2026-08-06 12:00:01');
        event(new ScheduledTaskFinished($task, 0.02));

        $this->assertSame(
            ScheduledTaskRunStatus::Running,
            ScheduledTaskRun::query()->sole()->status,
        );

        Carbon::setTestNow('2026-08-06 12:00:04');
        $task->exitCode = 0;
        event(new ScheduledBackgroundTaskFinished($task));

        $run = ScheduledTaskRun::query()->sole();

        $this->assertSame(ScheduledTaskRunStatus::Success, $run->status);
        $this->assertSame(4000, $run->duration_ms);
        $this->assertSame($correlationId, $run->correlation_id);
        $this->assertSame(1, ScheduledTaskRun::query()->count());
    }

    public function test_artisan_job_and_shell_identifiers_do_not_persist_arguments(): void
    {
        $artisanTask = $this->scheduledCommand('sync:wars --api-key=artisan-secret');
        $jobTask = app(Schedule::class)->job(ReconcileMilcomLifecycleJob::class);
        $shellTask = app(Schedule::class)->exec('backup-tool --password=shell-secret');

        event(new ScheduledTaskSkipped($artisanTask));
        event(new ScheduledTaskSkipped($jobTask));
        event(new ScheduledTaskSkipped($shellTask));

        $identifiers = ScheduledTaskRun::query()->orderBy('id')->pluck('task_identifier');

        $this->assertSame('artisan:sync:wars', $identifiers[0]);
        $this->assertSame('job:App.Jobs.ReconcileMilcomLifecycleJob', $identifiers[1]);
        $this->assertStringStartsWith('shell:', $identifiers[2]);
        $this->assertStringNotContainsString('artisan-secret', $this->serializedRuns());
        $this->assertStringNotContainsString('shell-secret', $this->serializedRuns());
    }

    public function test_freshness_uses_the_last_success_and_reports_missing_tasks_overdue(): void
    {
        $asOf = CarbonImmutable::parse('2026-08-06 12:00:00');
        Carbon::setTestNow($asOf);
        config()->set('scheduler_lifecycle.freshness_contracts', [
            'artisan:pw:health-check' => [
                'label' => 'P&W health',
                'maximum_age_minutes' => 5,
            ],
            'artisan:sync:wars' => [
                'label' => 'War sync',
                'maximum_age_minutes' => 5,
            ],
            'artisan:audits:run' => [
                'label' => 'Audits',
                'maximum_age_minutes' => 60,
            ],
        ]);

        ScheduledTaskRun::factory()->create([
            'task_identifier' => 'artisan:pw:health-check',
            'status' => ScheduledTaskRunStatus::Success,
            'finished_at' => $asOf->subMinutes(4),
        ]);
        ScheduledTaskRun::factory()->create([
            'task_identifier' => 'artisan:sync:wars',
            'status' => ScheduledTaskRunStatus::Success,
            'finished_at' => $asOf->subMinutes(6),
        ]);
        ScheduledTaskRun::factory()->failed()->create([
            'task_identifier' => 'artisan:sync:wars',
            'finished_at' => $asOf->subMinute(),
        ]);

        $service = app(ScheduledTaskFreshnessService::class);
        $snapshot = $service->snapshot($asOf);

        $this->assertFalse($snapshot->get('artisan:pw:health-check')->isOverdue);
        $this->assertTrue($snapshot->get('artisan:sync:wars')->isOverdue);
        $this->assertTrue($snapshot->get('artisan:audits:run')->isOverdue);
        $this->assertNull($snapshot->get('artisan:audits:run')->lastSucceededAt);
        $this->assertSame([
            'artisan:sync:wars',
            'artisan:audits:run',
        ], $service->overdue($asOf)->keys()->all());
    }

    public function test_pruning_uses_status_and_slow_run_retention_windows(): void
    {
        Carbon::setTestNow('2026-08-06 12:00:00');
        config()->set('scheduler_lifecycle.retention', [
            'routine_success_days' => 10,
            'slow_success_days' => 30,
            'slow_threshold_ms' => 1000,
            'failure_days' => 30,
            'skipped_days' => 5,
            'overlap_days' => 7,
            'running_days' => 2,
            'batch_size' => 2,
        ]);

        $oldRoutineSuccess = $this->historicalRun(ScheduledTaskRunStatus::Success, 11, 500);
        $retainedSlowSuccess = $this->historicalRun(ScheduledTaskRunStatus::Success, 11, 2000);
        $oldSlowSuccess = $this->historicalRun(ScheduledTaskRunStatus::Success, 31, 2000);
        $retainedFailure = $this->historicalRun(ScheduledTaskRunStatus::Failure, 11, 500);
        $oldFailure = $this->historicalRun(ScheduledTaskRunStatus::Failure, 31, 500);
        $oldSkip = $this->historicalRun(ScheduledTaskRunStatus::Skipped, 6, null);
        $oldOverlap = $this->historicalRun(ScheduledTaskRunStatus::Overlap, 8, 0);
        $abandonedRun = $this->historicalRun(ScheduledTaskRunStatus::Running, 3, null);
        $recentSuccess = $this->historicalRun(ScheduledTaskRunStatus::Success, 1, 500);

        $this->artisan('scheduler-lifecycle:prune')->assertSuccessful();

        $this->assertModelMissing($oldRoutineSuccess);
        $this->assertModelMissing($oldSlowSuccess);
        $this->assertModelMissing($oldFailure);
        $this->assertModelMissing($oldSkip);
        $this->assertModelMissing($oldOverlap);
        $this->assertModelMissing($abandonedRun);
        $this->assertModelExists($retainedSlowSuccess);
        $this->assertModelExists($retainedFailure);
        $this->assertModelExists($recentSuccess);
        $this->assertDatabaseCount('scheduled_task_runs', 3);
    }

    public function test_pruning_pretend_mode_does_not_delete_runs(): void
    {
        Carbon::setTestNow('2026-08-06 12:00:00');
        config()->set('scheduler_lifecycle.retention.routine_success_days', 1);
        $oldRun = $this->historicalRun(ScheduledTaskRunStatus::Success, 2, 500);

        $this->artisan('scheduler-lifecycle:prune', ['--pretend' => true])
            ->expectsOutputToContain('would prune')
            ->assertSuccessful();

        $this->assertModelExists($oldRun);
    }

    private function scheduledCommand(string $command): Event
    {
        return app(Schedule::class)->command($command);
    }

    private function serializedRuns(): string
    {
        return ScheduledTaskRun::query()
            ->get()
            ->map(fn (ScheduledTaskRun $run): string => json_encode(
                $run->getAttributes(),
                JSON_THROW_ON_ERROR,
            ))
            ->implode(' ');
    }

    private function historicalRun(
        ScheduledTaskRunStatus $status,
        int $daysAgo,
        ?int $durationMs,
    ): ScheduledTaskRun {
        $recordedAt = now()->subDays($daysAgo);

        return ScheduledTaskRun::factory()->create([
            'status' => $status,
            'scheduled_at' => $recordedAt,
            'started_at' => $status === ScheduledTaskRunStatus::Running
                ? $recordedAt
                : ($status === ScheduledTaskRunStatus::Skipped ? null : $recordedAt),
            'finished_at' => $status === ScheduledTaskRunStatus::Running ? null : $recordedAt,
            'duration_ms' => $durationMs,
            'exit_code' => $status === ScheduledTaskRunStatus::Failure ? 1 : null,
            'exception_class' => $status === ScheduledTaskRunStatus::Failure
                ? RuntimeException::class
                : null,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ]);
    }
}

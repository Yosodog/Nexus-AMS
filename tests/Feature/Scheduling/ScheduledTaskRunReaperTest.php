<?php

namespace Tests\Feature\Scheduling;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use App\Services\Scheduling\ScheduledTaskFreshnessService;
use App\Services\Scheduling\ScheduledTaskRunReaper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\FeatureTestCase;

class ScheduledTaskRunReaperTest extends FeatureTestCase
{
    use RefreshDatabase;

    private string $everyMinuteHash;

    private string $hourlyHash;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 12:00:00');
        config()->set('scheduler_lifecycle.abandonment', [
            'interval_multiple' => 10,
            'minimum_minutes' => 60,
            'maximum_minutes' => 1440,
            'max_rows_per_pass' => 10000,
        ]);
        config()->set('scheduler_lifecycle.retention.batch_size', 2);

        $schedule = app(Schedule::class);
        $this->everyMinuteHash = hash(
            'sha256',
            $schedule->command('reaper-test:every-minute')->everyMinute()->runInBackground()->mutexName(),
        );
        $this->hourlyHash = hash(
            'sha256',
            $schedule->command('reaper-test:hourly')->hourly()->runInBackground()->mutexName(),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_only_running_rows_past_their_task_bound_are_abandoned(): void
    {
        $stuckEveryMinute = $this->runningRow($this->everyMinuteHash, 61);
        $recentEveryMinute = $this->runningRow($this->everyMinuteHash, 59);
        $stuckHourly = $this->runningRow($this->hourlyHash, 601);
        $longHourly = $this->runningRow($this->hourlyHash, 300);
        $stuckUnscheduled = $this->runningRow(hash('sha256', 'no-longer-scheduled'), 1441);
        $recentUnscheduled = $this->runningRow(hash('sha256', 'no-longer-scheduled'), 1000);
        $finished = ScheduledTaskRun::factory()->create([
            'task_mutex_hash' => $this->everyMinuteHash,
            'started_at' => now()->subHours(5),
            'finished_at' => now()->subHours(5)->addSecond(),
            'duration_ms' => 1000,
        ]);

        $result = app(ScheduledTaskRunReaper::class)->reap();

        $this->assertSame(3, $result['abandoned']);

        foreach ([$stuckEveryMinute, $stuckHourly, $stuckUnscheduled] as $run) {
            $run->refresh();
            $this->assertSame(ScheduledTaskRunStatus::Abandoned, $run->status);
            $this->assertNull($run->finished_at);
            $this->assertNull($run->duration_ms);
        }

        foreach ([$recentEveryMinute, $longHourly, $recentUnscheduled] as $run) {
            $this->assertSame(ScheduledTaskRunStatus::Running, $run->refresh()->status);
        }

        $this->assertSame(ScheduledTaskRunStatus::Success, $finished->refresh()->status);
        $this->assertSame(1000, $finished->duration_ms);
    }

    public function test_durations_longer_than_the_task_bound_are_cleared_without_touching_the_outcome(): void
    {
        $cascaded = $this->completedRow($this->everyMinuteHash, 13_589_862);
        $cascadedFailure = $this->completedRow($this->everyMinuteHash, 4_000_000, ScheduledTaskRunStatus::Failure);
        $plausible = $this->completedRow($this->everyMinuteHash, 3_000_000);
        $plausibleHourly = $this->completedRow($this->hourlyHash, 13_589_862);
        $overlap = ScheduledTaskRun::factory()->overlapping()->create([
            'task_mutex_hash' => $this->everyMinuteHash,
            'duration_ms' => 0,
        ]);

        $finishedAt = $cascaded->finished_at->toDateTimeString();
        $result = app(ScheduledTaskRunReaper::class)->reap();

        $this->assertSame(2, $result['repaired']);

        $cascaded->refresh();
        $this->assertSame(ScheduledTaskRunStatus::Success, $cascaded->status);
        $this->assertNull($cascaded->duration_ms);
        $this->assertSame($finishedAt, $cascaded->finished_at->toDateTimeString());
        $this->assertSame(ScheduledTaskRunStatus::Failure, $cascadedFailure->refresh()->status);
        $this->assertNull($cascadedFailure->duration_ms);
        $this->assertSame(3_000_000, $plausible->refresh()->duration_ms);
        $this->assertSame(13_589_862, $plausibleHourly->refresh()->duration_ms);
        $this->assertSame(0, $overlap->refresh()->duration_ms);
    }

    public function test_reaping_is_idempotent(): void
    {
        $this->runningRow($this->everyMinuteHash, 120);
        $this->completedRow($this->everyMinuteHash, 13_589_862);

        $reaper = app(ScheduledTaskRunReaper::class);

        $this->assertSame(['abandoned' => 1, 'repaired' => 1], $reaper->reap());
        $this->assertSame(['abandoned' => 0, 'repaired' => 0], $reaper->reap());
    }

    public function test_each_pass_is_capped_and_the_remainder_is_handled_by_the_next_pass(): void
    {
        config()->set('scheduler_lifecycle.abandonment.max_rows_per_pass', 3);

        foreach (range(1, 5) as $offset) {
            $this->runningRow($this->everyMinuteHash, 120 + $offset);
        }

        $reaper = app(ScheduledTaskRunReaper::class);

        $this->assertSame(3, $reaper->reap()['abandoned']);
        $this->assertSame(2, $reaper->reap()['abandoned']);
        $this->assertSame(0, ScheduledTaskRun::query()
            ->where('status', ScheduledTaskRunStatus::Running->value)
            ->count());
    }

    public function test_pretend_mode_reports_without_changing_rows(): void
    {
        $stuck = $this->runningRow($this->everyMinuteHash, 120);
        $cascaded = $this->completedRow($this->everyMinuteHash, 13_589_862);

        $this->artisan('scheduler-lifecycle:prune', ['--pretend' => true])
            ->expectsOutputToContain('1 stuck running records would mark abandoned; 1 implausible durations would be cleared.')
            ->assertSuccessful();

        $this->assertSame(ScheduledTaskRunStatus::Running, $stuck->refresh()->status);
        $this->assertSame(13_589_862, $cascaded->refresh()->duration_ms);
    }

    public function test_prune_command_reaps_and_later_prunes_abandoned_rows(): void
    {
        config()->set('scheduler_lifecycle.retention.abandoned_days', 30);
        $stuck = $this->runningRow($this->everyMinuteHash, 120);
        $oldAbandoned = ScheduledTaskRun::factory()->abandoned()->create([
            'task_mutex_hash' => $this->everyMinuteHash,
            'started_at' => now()->subDays(31),
            'created_at' => now()->subDays(31),
        ]);

        $this->artisan('scheduler-lifecycle:prune')
            ->expectsOutputToContain('1 stuck running records marked abandoned')
            ->assertSuccessful();

        $this->assertSame(ScheduledTaskRunStatus::Abandoned, $stuck->refresh()->status);
        $this->assertModelMissing($oldAbandoned);
    }

    public function test_freshness_is_unchanged_by_reaping(): void
    {
        config()->set('scheduler_lifecycle.freshness_contracts', [
            'artisan:reaper-test:every-minute' => [
                'label' => 'Every minute',
                'maximum_age_minutes' => 5,
            ],
        ]);
        $this->completedRow($this->everyMinuteHash, 13_589_862, finishedMinutesAgo: 2);
        $this->runningRow($this->everyMinuteHash, 120);

        $freshness = app(ScheduledTaskFreshnessService::class);
        $before = $freshness->snapshot()->get('artisan:reaper-test:every-minute');

        app(ScheduledTaskRunReaper::class)->reap();

        $after = $freshness->snapshot()->get('artisan:reaper-test:every-minute');

        $this->assertFalse($after->isOverdue);
        $this->assertEquals($before->lastSucceededAt, $after->lastSucceededAt);
    }

    private function runningRow(string $mutexHash, int $startedMinutesAgo): ScheduledTaskRun
    {
        $startedAt = now()->subMinutes($startedMinutesAgo);

        return ScheduledTaskRun::factory()->running()->create([
            'task_identifier' => 'artisan:reaper-test:every-minute',
            'task_mutex_hash' => $mutexHash,
            'scheduled_at' => $startedAt,
            'started_at' => $startedAt,
            'created_at' => $startedAt,
        ]);
    }

    private function completedRow(
        string $mutexHash,
        int $durationMs,
        ScheduledTaskRunStatus $status = ScheduledTaskRunStatus::Success,
        int $finishedMinutesAgo = 10,
    ): ScheduledTaskRun {
        $finishedAt = now()->subMinutes($finishedMinutesAgo);

        return ScheduledTaskRun::factory()->create([
            'task_identifier' => 'artisan:reaper-test:every-minute',
            'task_mutex_hash' => $mutexHash,
            'status' => $status,
            'started_at' => $finishedAt->copy()->subMilliseconds($durationMs),
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
        ]);
    }
}

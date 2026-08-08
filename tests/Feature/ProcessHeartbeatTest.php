<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\NexusScheduleRegistrar;
use App\Enums\ProcessHeartbeatRole;
use App\Jobs\RecordQueueHeartbeat;
use App\Services\ProcessHeartbeatRecorder;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class ProcessHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_idempotently_updates_each_process_role_for_the_current_release(): void
    {
        config(['nexus.release_id' => 'release-a']);
        $this->travelTo('2026-08-08 20:00:00');

        $recorder = app(ProcessHeartbeatRecorder::class);
        $recorder->record(ProcessHeartbeatRole::Scheduler);
        $createdAt = DB::table('process_heartbeats')
            ->where('role', ProcessHeartbeatRole::Scheduler->value)
            ->value('created_at');

        config(['nexus.release_id' => 'release-b']);
        $this->travelTo('2026-08-08 20:01:00');
        $recorder->record(ProcessHeartbeatRole::Scheduler);

        $this->assertDatabaseCount('process_heartbeats', 1);
        $this->assertDatabaseHas('process_heartbeats', [
            'role' => ProcessHeartbeatRole::Scheduler->value,
            'release_id' => 'release-b',
            'last_seen_at' => '2026-08-08 20:01:00',
            'created_at' => $createdAt,
        ]);
    }

    public function test_invalid_release_metadata_is_replaced_with_a_bounded_placeholder(): void
    {
        config(['nexus.release_id' => "release\nsecret"]);

        app(ProcessHeartbeatRecorder::class)->record(ProcessHeartbeatRole::Queue);

        $this->assertDatabaseHas('process_heartbeats', [
            'role' => ProcessHeartbeatRole::Queue->value,
            'release_id' => 'unknown',
        ]);
    }

    public function test_queue_job_records_a_retry_safe_queue_heartbeat(): void
    {
        config(['nexus.release_id' => 'queue-release']);
        $job = new RecordQueueHeartbeat;

        $job->handle(app(ProcessHeartbeatRecorder::class));
        $job->handle(app(ProcessHeartbeatRecorder::class));

        $this->assertSame('runtime-process-heartbeat:queue', $job->uniqueId());
        $this->assertSame([5, 15, 30], $job->backoff());
        $this->assertDatabaseCount('process_heartbeats', 1);
        $this->assertDatabaseHas('process_heartbeats', [
            'role' => ProcessHeartbeatRole::Queue->value,
            'release_id' => 'queue-release',
        ]);
    }

    public function test_scheduled_heartbeat_records_the_scheduler_and_dispatches_to_the_server_queue(): void
    {
        Queue::fake();
        config([
            'nexus.release_id' => 'scheduler-release',
            'nexus.health.queue' => 'tenant-health',
        ]);

        $schedule = new Schedule;
        app(NexusScheduleRegistrar::class)->register($schedule);
        $event = collect($schedule->events())->first(
            fn (mixed $event): bool => $event instanceof CallbackEvent
                && $event->description === 'health:record-process-heartbeats',
        );

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $event->run($this->app);

        $this->assertDatabaseHas('process_heartbeats', [
            'role' => ProcessHeartbeatRole::Scheduler->value,
            'release_id' => 'scheduler-release',
        ]);
        Queue::assertPushed(
            RecordQueueHeartbeat::class,
            fn (RecordQueueHeartbeat $job): bool => $job->queue === 'tenant-health',
        );
    }

    public function test_migration_resumes_after_table_creation_and_repairs_a_missing_age_index(): void
    {
        DB::table('process_heartbeats')->insert([
            'role' => ProcessHeartbeatRole::Queue->value,
            'release_id' => 'preserved-release',
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::table('process_heartbeats', function (Blueprint $table): void {
            $table->dropIndex('process_heartbeats_last_seen_at_index');
        });

        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex('process_heartbeats', ['last_seen_at']));
        $this->assertDatabaseHas('process_heartbeats', [
            'role' => ProcessHeartbeatRole::Queue->value,
            'release_id' => 'preserved-release',
        ]);
    }

    public function test_migration_refuses_an_incomplete_preexisting_table_for_forward_recovery(): void
    {
        Schema::drop('process_heartbeats');
        Schema::create('process_heartbeats', function (Blueprint $table): void {
            $table->string('role', 32)->primary();
        });

        try {
            $this->migration()->up();
            $this->fail('The incomplete heartbeat table was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The process_heartbeats table is incomplete; repair it before resuming migration.',
                $exception->getMessage(),
            );
        } finally {
            Schema::dropIfExists('process_heartbeats');
            $this->migration()->up();
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_08_203652_create_process_heartbeats_table.php');
    }
}

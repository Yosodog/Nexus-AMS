<?php

namespace Tests\Integration;

use App\Enums\BackupRunStatus;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\Backups\BackupRunService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class BackupRunPendingKeyConstraintTest extends MySqlIntegrationTestCase
{
    public function test_only_one_backup_can_be_queued_or_running(): void
    {
        BackupRun::factory()->queued()->create();

        $this->expectException(QueryException::class);
        BackupRun::factory()->running()->create();
    }

    public function test_finished_runs_clear_the_pending_key_and_keep_history(): void
    {
        $first = BackupRun::factory()->running()->create();
        app(BackupRunService::class)->finish($first, ['succeeded' => true, 'path' => 'Nexus/a.zip', 'size' => 1, 'error' => null]);

        BackupRun::factory()->queued()->create();
        BackupRun::factory()->count(3)->create();

        $this->assertSame(5, BackupRun::query()->count());
        $this->assertNull($first->refresh()->pending_key);
    }

    public function test_concurrent_manual_request_becomes_a_validation_error(): void
    {
        $user = User::factory()->create();
        BackupRun::query()->insert([
            'trigger' => 'scheduled',
            'status' => BackupRunStatus::Queued->value,
            'pending_key' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(BackupRunService::class)->requestManual($user);
    }
}

<?php

namespace Tests\Feature\Migrations;

use App\Models\OperationsWorkCoordination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseMemberTransferOperationsWorkItemsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forward_migration_closes_only_active_member_transfer_work_items(): void
    {
        $assignee = User::factory()->create();
        $memberTransfer = OperationsWorkCoordination::factory()->create([
            'work_key' => 'member_transfers:123',
            'source_type' => 'member_transfers',
            'assignee_user_id' => $assignee->id,
            'assigned_by_user_id' => $assignee->id,
            'assigned_at' => now()->subMinute(),
            'assignment_expires_at' => now()->addMinutes(10),
            'lock_version' => 4,
        ]);
        $application = OperationsWorkCoordination::factory()->create([
            'work_key' => 'applications:456',
            'source_type' => 'applications',
        ]);

        $this->migration()->up();

        $memberTransfer->refresh();
        $application->refresh();

        $this->assertNotNull($memberTransfer->closed_at);
        $this->assertNull($memberTransfer->active_key);
        $this->assertNull($memberTransfer->assignee_user_id);
        $this->assertNull($memberTransfer->assigned_by_user_id);
        $this->assertNull($memberTransfer->assigned_at);
        $this->assertNull($memberTransfer->assignment_expires_at);
        $this->assertSame(5, $memberTransfer->lock_version);
        $this->assertNull($application->closed_at);
        $this->assertSame(OperationsWorkCoordination::ACTIVE_KEY_VALUE, $application->active_key);
        $this->assertDatabaseHas('operations_work_events', [
            'coordination_id' => $memberTransfer->id,
            'source_type' => 'member_transfers',
            'event_type' => 'closed',
            'subject_user_id' => $assignee->id,
            'idempotency_key' => 'source-retired:member_transfers:'.$memberTransfer->id,
        ]);
        $this->assertDatabaseMissing('operations_work_events', [
            'coordination_id' => $application->id,
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_20_003537_close_member_transfer_operations_work_items.php');
    }
}

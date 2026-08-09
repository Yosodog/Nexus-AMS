<?php

namespace Tests\Feature;

use App\Models\OperationsWorkCoordination;
use App\Models\OperationsWorkEvent;
use App\Models\User;
use App\Services\StaffWorkQueue\OperationsCoordinationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsCoordinationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_discovers_changes_and_expires_assignment_with_audit_history(): void
    {
        $reconciler = app(OperationsCoordinationReconciler::class);
        $initial = $this->item('loans:42', 'occurrence-1', 'revision-1');

        $this->assertSame([
            'discovered' => 1,
            'changed' => 0,
            'reopened' => 0,
            'closed' => 0,
            'expired' => 0,
            'skipped_closure' => false,
        ], $reconciler->reconcile($this->snapshot([$initial])));

        $assignee = User::factory()->create();
        $coordination = OperationsWorkCoordination::query()->firstOrFail();
        $coordination->forceFill([
            'assignee_user_id' => $assignee->id,
            'assigned_by_user_id' => $assignee->id,
            'assigned_at' => now()->subHour(),
            'assignment_expires_at' => now()->subMinute(),
        ])->save();

        $changed = $this->item('loans:42', 'occurrence-1', 'revision-2');
        $result = $reconciler->reconcile($this->snapshot([$changed]));

        $this->assertSame(1, $result['changed']);
        $this->assertSame(1, $result['expired']);
        $coordination->refresh();
        $this->assertSame(hash('sha256', 'revision-2'), $coordination->source_fingerprint);
        $this->assertNull($coordination->assignee_user_id);
        $this->assertNull($coordination->assigned_at);
        $this->assertNull($coordination->assignment_expires_at);
        $this->assertSame(2, $coordination->lock_version);
        $this->assertSame(
            ['discovered', 'assignment_expired', 'changed'],
            OperationsWorkEvent::query()->orderBy('id')->pluck('event_type')->all(),
        );
        $this->assertDatabaseHas('operations_work_events', [
            'coordination_id' => $coordination->id,
            'event_type' => 'assignment_expired',
            'subject_user_id' => $assignee->id,
        ]);
    }

    public function test_only_a_complete_successful_source_can_close_missing_work(): void
    {
        $reconciler = app(OperationsCoordinationReconciler::class);
        $assignee = User::factory()->create();
        $coordination = OperationsWorkCoordination::factory()->create([
            'work_key' => 'loans:42',
            'occurrence_key' => 'occurrence-1',
            'source_type' => 'loans',
            'assignee_user_id' => $assignee->id,
            'assigned_by_user_id' => $assignee->id,
            'assigned_at' => now(),
            'assignment_expires_at' => now()->addMinutes(30),
        ]);

        $incomplete = $reconciler->reconcile($this->snapshot([], complete: false));
        $this->assertTrue($incomplete['skipped_closure']);
        $this->assertSame(0, $incomplete['closed']);
        $this->assertNotNull($coordination->fresh()->active_key);

        $truncated = $reconciler->reconcile($this->snapshot([], truncated: true));
        $this->assertTrue($truncated['skipped_closure']);
        $this->assertSame(0, $truncated['closed']);
        $this->assertNotNull($coordination->fresh()->active_key);

        $coordination->forceFill(['assignment_expires_at' => now()->subMinute()])->save();
        $failed = $reconciler->reconcile($this->snapshot([], state: 'failed'));
        $this->assertTrue($failed['skipped_closure']);
        $this->assertSame(0, $failed['closed']);
        $this->assertSame(1, $failed['expired']);
        $coordination->refresh();
        $this->assertNotNull($coordination->active_key);
        $this->assertNull($coordination->assignee_user_id);
        $this->assertDatabaseHas('operations_work_events', [
            'coordination_id' => $coordination->id,
            'event_type' => 'assignment_expired',
            'subject_user_id' => $assignee->id,
        ]);

        $complete = $reconciler->reconcile($this->snapshot([]));
        $this->assertFalse($complete['skipped_closure']);
        $this->assertSame(1, $complete['closed']);

        $coordination->refresh();
        $this->assertNull($coordination->active_key);
        $this->assertNotNull($coordination->closed_at);
        $this->assertNull($coordination->assignee_user_id);
        $this->assertNull($coordination->assigned_at);
        $this->assertNull($coordination->assignment_expires_at);
        $this->assertSame(3, $coordination->lock_version);
        $event = OperationsWorkEvent::query()->where('event_type', 'closed')->firstOrFail();
        $this->assertNull($event->subject_user_id);
        $this->assertSame(['reason' => 'missing_from_complete_source'], $event->metadata);
    }

    public function test_new_occurrence_closes_old_ownership_and_records_reopen(): void
    {
        $reconciler = app(OperationsCoordinationReconciler::class);
        $assignee = User::factory()->create();
        $old = OperationsWorkCoordination::factory()->create([
            'work_key' => 'loans:42',
            'occurrence_key' => 'occurrence-1',
            'source_type' => 'loans',
            'assignee_user_id' => $assignee->id,
            'assigned_by_user_id' => $assignee->id,
            'assigned_at' => now(),
            'assignment_expires_at' => now()->addMinutes(30),
        ]);

        $result = $reconciler->reconcile($this->snapshot([
            $this->item('loans:42', 'occurrence-2', 'revision-2'),
        ]));

        $this->assertSame(1, $result['reopened']);
        $this->assertSame(1, $result['closed']);
        $this->assertSame(0, $result['discovered']);
        $old->refresh();
        $this->assertNull($old->active_key);
        $this->assertNotNull($old->closed_at);
        $this->assertNull($old->assignee_user_id);
        $this->assertNull($old->assignment_expires_at);

        $active = OperationsWorkCoordination::query()->active()->firstOrFail();
        $this->assertSame('occurrence-2', $active->occurrence_key);
        $this->assertNull($active->assignee_user_id);
        $this->assertDatabaseHas('operations_work_events', [
            'coordination_id' => $old->id,
            'event_type' => 'closed',
            'subject_user_id' => $assignee->id,
        ]);
        $this->assertDatabaseHas('operations_work_events', [
            'coordination_id' => $active->id,
            'event_type' => 'reopened',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function snapshot(
        array $items,
        bool $complete = true,
        bool $truncated = false,
        string $state = 'available',
    ): array {
        return [
            'type' => 'loans',
            'items' => $items,
            'complete' => $complete,
            'truncated' => $truncated,
            'state' => $state,
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $workKey, string $occurrenceKey, string $revision): array
    {
        return [
            'work_key' => $workKey,
            'occurrence_key' => $occurrenceKey,
            'source_type' => 'loans',
            'source_fingerprint' => hash('sha256', $revision),
            'source_updated_at' => now()->toIso8601String(),
            'team_key' => 'finance',
        ];
    }
}

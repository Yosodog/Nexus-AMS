<?php

namespace Tests\Feature;

use App\Models\OperationsActionIntent;
use App\Models\OperationsSourceState;
use App\Models\OperationsTeamSavedView;
use App\Models\OperationsWorkCoordination;
use App\Models\OperationsWorkEvent;
use App\Models\OperationsWorkWatch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_cast_json_dates_and_resolve_relationships(): void
    {
        $user = User::factory()->create();
        $coordination = OperationsWorkCoordination::factory()->create([
            'assignee_user_id' => $user->id,
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
            'assignment_expires_at' => now()->addMinutes(30),
            'triage_acknowledged_by_user_id' => $user->id,
            'escalated_by_user_id' => $user->id,
        ]);
        $event = OperationsWorkEvent::factory()->create([
            'coordination_id' => $coordination->id,
            'work_key' => $coordination->work_key,
            'occurrence_key' => $coordination->occurrence_key,
            'actor_user_id' => $user->id,
            'subject_user_id' => $user->id,
            'metadata' => ['kind' => 'assignment'],
        ]);
        $watch = OperationsWorkWatch::factory()->create([
            'coordination_id' => $coordination->id,
            'user_id' => $user->id,
            'muted_until' => now()->addHour(),
        ]);
        $view = OperationsTeamSavedView::factory()->create([
            'created_by_user_id' => $user->id,
            'filters' => ['team' => 'finance'],
        ]);
        $intent = OperationsActionIntent::factory()->create([
            'actor_user_id' => $user->id,
            'payload' => ['work_key' => $coordination->work_key],
            'result' => ['preview' => true],
        ]);
        $sourceState = OperationsSourceState::factory()->create([
            'projected_at' => now(),
            'item_count' => 12,
        ]);

        $this->assertInstanceOf(Carbon::class, $coordination->first_seen_at);
        $this->assertInstanceOf(Carbon::class, $coordination->assignment_expires_at);
        $this->assertTrue($coordination->assignment_expires_at->isAfter($coordination->assigned_at));
        $this->assertSame(1, $coordination->lock_version);
        $this->assertSame(['kind' => 'assignment'], $event->metadata);
        $this->assertInstanceOf(Carbon::class, $watch->muted_until);
        $this->assertSame(['team' => 'finance'], $view->filters);
        $this->assertSame(['work_key' => $coordination->work_key], $intent->payload);
        $this->assertSame(['preview' => true], $intent->result);
        $this->assertSame(12, $sourceState->item_count);

        $this->assertTrue($coordination->assignee->is($user));
        $this->assertTrue($coordination->assignedBy->is($user));
        $this->assertTrue($coordination->triageAcknowledgedBy->is($user));
        $this->assertTrue($coordination->escalatedBy->is($user));
        $this->assertTrue($coordination->events->contains($event));
        $this->assertTrue($coordination->watches->contains($watch));
        $this->assertTrue($event->coordination->is($coordination));
        $this->assertTrue($event->actor->is($user));
        $this->assertTrue($event->subject->is($user));
        $this->assertTrue($watch->coordination->is($coordination));
        $this->assertTrue($watch->user->is($user));
        $this->assertTrue($view->createdBy->is($user));
        $this->assertTrue($intent->actor->is($user));
        $this->assertSame($view->public_id, $view->getRouteKey());
    }

    public function test_coordination_enforces_occurrence_and_active_uniqueness(): void
    {
        $workKey = 'applications:unique';
        $occurrenceKey = 'occurrence-1';

        OperationsWorkCoordination::factory()->create([
            'work_key' => $workKey,
            'occurrence_key' => $occurrenceKey,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        OperationsWorkCoordination::factory()->create([
            'work_key' => $workKey,
            'occurrence_key' => $occurrenceKey,
        ]);
    }

    public function test_coordination_allows_multiple_closed_occurrences_but_one_active_occurrence(): void
    {
        $workKey = 'applications:active';

        OperationsWorkCoordination::factory()->create([
            'work_key' => $workKey,
            'occurrence_key' => 'closed-1',
            'active_key' => null,
            'closed_at' => now(),
        ]);
        OperationsWorkCoordination::factory()->create([
            'work_key' => $workKey,
            'occurrence_key' => 'closed-2',
            'active_key' => null,
            'closed_at' => now(),
        ]);
        OperationsWorkCoordination::factory()->create([
            'work_key' => $workKey,
            'occurrence_key' => 'active-1',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        OperationsWorkCoordination::factory()->create([
            'work_key' => $workKey,
            'occurrence_key' => 'active-2',
        ]);
    }

    public function test_event_idempotency_is_unique(): void
    {
        $coordination = OperationsWorkCoordination::factory()->create();
        $idempotencyKey = 'event-once';

        OperationsWorkEvent::factory()->create([
            'coordination_id' => $coordination->id,
            'work_key' => $coordination->work_key,
            'occurrence_key' => $coordination->occurrence_key,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        OperationsWorkEvent::factory()->create([
            'coordination_id' => $coordination->id,
            'work_key' => $coordination->work_key,
            'occurrence_key' => $coordination->occurrence_key,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function test_event_users_are_nullable_on_delete_and_history_is_preserved(): void
    {
        $user = User::factory()->create();
        $coordination = OperationsWorkCoordination::factory()->create();
        $event = OperationsWorkEvent::factory()->create([
            'coordination_id' => $coordination->id,
            'work_key' => $coordination->work_key,
            'occurrence_key' => $coordination->occurrence_key,
            'actor_user_id' => $user->id,
            'subject_user_id' => $user->id,
        ]);

        $user->delete();
        $event->refresh();

        $this->assertModelExists($event);
        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->subject_user_id);
    }

    public function test_watch_pair_is_unique(): void
    {
        $coordination = OperationsWorkCoordination::factory()->create();
        $user = User::factory()->create();
        OperationsWorkWatch::factory()->create([
            'coordination_id' => $coordination->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        OperationsWorkWatch::factory()->create([
            'coordination_id' => $coordination->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_watches_cascade_with_coordination(): void
    {
        $coordination = OperationsWorkCoordination::factory()->create();
        $watch = OperationsWorkWatch::factory()->create([
            'coordination_id' => $coordination->id,
        ]);

        $coordination->delete();

        $this->assertDatabaseMissing('operations_work_watches', ['id' => $watch->id]);
    }

    public function test_team_view_name_is_unique_within_a_team(): void
    {
        OperationsTeamSavedView::factory()->create([
            'team_key' => 'finance',
            'name' => 'Needs attention',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        OperationsTeamSavedView::factory()->create([
            'team_key' => 'finance',
            'name' => 'Needs attention',
        ]);
    }

    public function test_action_token_constraint_is_enforced(): void
    {
        $intent = OperationsActionIntent::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        OperationsActionIntent::factory()->create([
            'token_hash' => $intent->token_hash,
        ]);
    }

    public function test_source_type_constraint_is_enforced(): void
    {
        OperationsSourceState::factory()->create(['source_type' => 'applications']);

        $this->expectException(UniqueConstraintViolationException::class);

        OperationsSourceState::factory()->create(['source_type' => 'applications']);
    }
}

<?php

namespace App\Services\Admin\MemberTimeline;

use App\DataTransferObjects\Admin\MemberTimelineItem;
use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Enums\MemberTimelineCategory;
use App\Models\MilcomAssignment;
use App\Models\MilcomAssignmentDelivery;
use App\Models\MilcomEvent;
use App\Models\Nation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class MilitaryTimelineSource implements MemberTimelineSource
{
    /** @var list<string> */
    private const ASSIGNMENT_EVENT_TYPES = [
        'assignment.alternative_selected',
        'assignment.completed',
        'assignment.engaged',
        'assignment.in_game_sent',
        'assignment.manually_set',
        'assignment.released',
    ];

    public function category(): MemberTimelineCategory
    {
        return MemberTimelineCategory::Military;
    }

    public function visibleTo(User $viewer): bool
    {
        return $viewer->can('manage-war-room');
    }

    public function items(Nation $nation, User $viewer, int $recordLimit): Collection
    {
        $assignments = MilcomAssignment::query()
            ->select([
                'id',
                'objective_id',
                'friendly_nation_id',
                'status',
                'approved_at',
                'dispatched_at',
                'engaged_at',
                'completed_at',
                'released_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'objective:id,operation_id',
                'objective.operation:id,type,status',
            ])
            ->where('friendly_nation_id', $nation->id)
            ->latest('created_at')
            ->limit($recordLimit)
            ->get();

        if ($assignments->isEmpty()) {
            return collect();
        }

        $items = $assignments
            ->map(fn (MilcomAssignment $assignment): MemberTimelineItem => $this->assignmentItem($assignment));
        $assignmentIds = $assignments->pluck('id')->all();
        $assignmentsById = $assignments->keyBy('id');

        $events = MilcomEvent::query()
            ->select([
                'id',
                'operation_id',
                'objective_id',
                'assignment_id',
                'actor_user_id',
                'source',
                'event_type',
                'occurred_at',
            ])
            ->with([
                'actor' => fn ($query) => $query
                    ->without('roles')
                    ->select(['id', 'name', 'is_admin']),
            ])
            ->whereIn('assignment_id', $assignmentIds)
            ->whereIn('event_type', self::ASSIGNMENT_EVENT_TYPES)
            ->latest('occurred_at')
            ->limit($recordLimit)
            ->get()
            ->map(function (MilcomEvent $event) use ($assignmentsById): MemberTimelineItem {
                /** @var MilcomAssignment|null $assignment */
                $assignment = $assignmentsById->get($event->assignment_id);

                return $this->eventItem($event, $assignment);
            });
        $deliveries = MilcomAssignmentDelivery::query()
            ->select([
                'id',
                'operation_id',
                'assignment_id',
                'channel',
                'status',
                'queued_at',
                'sent_at',
                'failed_at',
                'created_at',
            ])
            ->whereIn('assignment_id', $assignmentIds)
            ->latest('created_at')
            ->limit($recordLimit)
            ->get()
            ->map(function (MilcomAssignmentDelivery $delivery) use ($assignmentsById): MemberTimelineItem {
                /** @var MilcomAssignment|null $assignment */
                $assignment = $assignmentsById->get($delivery->assignment_id);

                return $this->deliveryItem($delivery, $assignment);
            });

        return $items->concat($events)->concat($deliveries)->values();
    }

    private function assignmentItem(MilcomAssignment $assignment): MemberTimelineItem
    {
        $status = $assignment->status instanceof AssignmentStatus
            ? $assignment->status
            : AssignmentStatus::tryFrom((string) $assignment->status) ?? AssignmentStatus::Proposed;
        $presentation = $this->assignmentPresentation($status);
        $occurredAt = match ($status) {
            AssignmentStatus::Approved => $assignment->approved_at,
            AssignmentStatus::Dispatched => $assignment->dispatched_at,
            AssignmentStatus::Engaged => $assignment->engaged_at,
            AssignmentStatus::Completed => $assignment->completed_at,
            AssignmentStatus::Released => $assignment->released_at,
            AssignmentStatus::Failed => $assignment->updated_at,
            default => $assignment->created_at,
        } ?? $assignment->created_at;

        return new MemberTimelineItem(
            sourceKey: "milcom-assignment:{$assignment->id}:current",
            deduplicationKey: "milcom-assignment:{$assignment->id}:{$status->value}",
            category: $this->category(),
            occurredAt: CarbonImmutable::instance($occurredAt),
            actorKind: 'system',
            actorLabel: 'Milcom',
            summary: "Milcom assignment {$presentation['summary']}.",
            statusLabel: $presentation['label'],
            statusIntent: $presentation['intent'],
            statusIcon: $presentation['icon'],
            sourceUrl: $this->sourceUrl($assignment),
            sourceLabel: "Milcom assignment #{$assignment->id}",
            sourcePriority: 10,
        );
    }

    private function eventItem(MilcomEvent $event, ?MilcomAssignment $assignment): MemberTimelineItem
    {
        $presentation = $this->eventPresentation((string) $event->event_type);
        $actorKind = $event->actor === null
            ? 'system'
            : ((bool) $event->actor->is_admin ? 'staff' : 'member');
        $deduplicationKey = $event->event_type === 'assignment.in_game_sent'
            ? "milcom-delivery:{$event->assignment_id}:in_game:sent"
            : "milcom-assignment:{$event->assignment_id}:{$presentation['action']}";

        return new MemberTimelineItem(
            sourceKey: "milcom-event:{$event->id}",
            deduplicationKey: $deduplicationKey,
            category: $this->category(),
            occurredAt: CarbonImmutable::instance($event->occurred_at),
            actorKind: $actorKind,
            actorLabel: $event->actor?->name ?? 'Milcom',
            summary: $presentation['summary'],
            statusLabel: $presentation['label'],
            statusIntent: $presentation['intent'],
            statusIcon: $presentation['icon'],
            sourceUrl: $this->sourceUrl($assignment),
            sourceLabel: $assignment !== null ? "Milcom assignment #{$assignment->id}" : 'Milcom operation',
            sourcePriority: 100,
        );
    }

    private function deliveryItem(
        MilcomAssignmentDelivery $delivery,
        ?MilcomAssignment $assignment,
    ): MemberTimelineItem {
        $presentation = match ((string) $delivery->status) {
            'sent' => ['label' => 'Delivered', 'intent' => 'success', 'icon' => 'check-circle'],
            'failed' => ['label' => 'Delivery failed', 'intent' => 'failure', 'icon' => 'x-circle'],
            default => ['label' => 'Queued', 'intent' => 'pending', 'icon' => 'clock'],
        };
        $channel = match ((string) $delivery->channel) {
            'discord' => 'Discord',
            'in_game' => 'in-game messaging',
            default => 'an approved channel',
        };
        $occurredAt = $delivery->failed_at
            ?? $delivery->sent_at
            ?? $delivery->queued_at
            ?? $delivery->created_at;

        return new MemberTimelineItem(
            sourceKey: "milcom-delivery:{$delivery->id}",
            deduplicationKey: "milcom-delivery:{$delivery->assignment_id}:{$delivery->channel}:{$delivery->status}",
            category: $this->category(),
            occurredAt: CarbonImmutable::instance($occurredAt),
            actorKind: 'system',
            actorLabel: 'Assignment delivery',
            summary: "Assignment delivery via {$channel}.",
            statusLabel: $presentation['label'],
            statusIntent: $presentation['intent'],
            statusIcon: $presentation['icon'],
            sourceUrl: $this->sourceUrl($assignment),
            sourceLabel: $assignment !== null ? "Milcom assignment #{$assignment->id}" : 'Milcom operation',
            sourcePriority: 70,
        );
    }

    private function sourceUrl(?MilcomAssignment $assignment): ?string
    {
        if (! config('milcom.v2_enabled', true) || $assignment?->objective?->operation === null) {
            return null;
        }

        $operation = $assignment->objective->operation;

        if ($operation->type === OperationType::Plan) {
            return route('admin.milcom.plans.show', [
                'operation' => $operation->id,
                'objective' => $assignment->objective_id,
            ]);
        }

        return route('admin.milcom.counters', [
            'operation' => $operation->id,
            'objective' => $assignment->objective_id,
        ]);
    }

    /** @return array{summary: string, label: string, intent: string, icon: string} */
    private function assignmentPresentation(AssignmentStatus $status): array
    {
        return match ($status) {
            AssignmentStatus::Proposed => ['summary' => 'proposed', 'label' => 'Proposed', 'intent' => 'neutral', 'icon' => 'pencil-square'],
            AssignmentStatus::Approved => ['summary' => 'approved', 'label' => 'Approved', 'intent' => 'active', 'icon' => 'check-circle'],
            AssignmentStatus::Dispatched => ['summary' => 'dispatched', 'label' => 'Dispatched', 'intent' => 'active', 'icon' => 'paper-airplane'],
            AssignmentStatus::Engaged => ['summary' => 'engaged', 'label' => 'Engaged', 'intent' => 'active', 'icon' => 'bolt'],
            AssignmentStatus::Completed => ['summary' => 'completed', 'label' => 'Completed', 'intent' => 'success', 'icon' => 'check-circle'],
            AssignmentStatus::Released => ['summary' => 'released', 'label' => 'Released', 'intent' => 'neutral', 'icon' => 'minus-circle'],
            AssignmentStatus::Failed => ['summary' => 'failed', 'label' => 'Failed', 'intent' => 'failure', 'icon' => 'x-circle'],
        };
    }

    /** @return array{action: string, summary: string, label: string, intent: string, icon: string} */
    private function eventPresentation(string $eventType): array
    {
        return match ($eventType) {
            'assignment.engaged' => ['action' => 'engaged', 'summary' => 'Milcom assignment marked engaged.', 'label' => 'Engaged', 'intent' => 'active', 'icon' => 'bolt'],
            'assignment.completed' => ['action' => 'completed', 'summary' => 'Milcom assignment marked complete.', 'label' => 'Completed', 'intent' => 'success', 'icon' => 'check-circle'],
            'assignment.released' => ['action' => 'released', 'summary' => 'Milcom assignment released.', 'label' => 'Released', 'intent' => 'neutral', 'icon' => 'minus-circle'],
            'assignment.in_game_sent' => ['action' => 'in-game-sent', 'summary' => 'Milcom assignment delivered in game.', 'label' => 'Delivered', 'intent' => 'success', 'icon' => 'check-circle'],
            'assignment.manually_set' => ['action' => 'approved', 'summary' => 'Milcom assignment set by an officer.', 'label' => 'Assigned', 'intent' => 'active', 'icon' => 'pencil-square'],
            default => ['action' => 'selection', 'summary' => 'Milcom assignment team updated.', 'label' => 'Updated', 'intent' => 'active', 'icon' => 'arrow-path'],
        };
    }
}

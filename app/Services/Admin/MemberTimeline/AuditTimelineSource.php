<?php

namespace App\Services\Admin\MemberTimeline;

use App\DataTransferObjects\Admin\MemberTimelineItem;
use App\Enums\MemberTimelineCategory;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\Nation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

final class AuditTimelineSource implements MemberTimelineSource
{
    public function category(): MemberTimelineCategory
    {
        return MemberTimelineCategory::Audits;
    }

    public function visibleTo(User $viewer): bool
    {
        return $viewer->can('view-audits');
    }

    public function items(Nation $nation, User $viewer, int $recordLimit): Collection
    {
        $events = AuditResultEvent::query()
            ->select([
                'id',
                'audit_result_id',
                'audit_rule_id',
                'nation_id',
                'actor_user_id',
                'event_type',
                'occurred_at',
            ])
            ->with([
                'actor' => fn ($query) => $query
                    ->without('roles')
                    ->select(['id', 'name', 'is_admin']),
            ])
            ->where('nation_id', $nation->id)
            ->latest('occurred_at')
            ->limit($recordLimit)
            ->get()
            ->map(fn (AuditResultEvent $event): MemberTimelineItem => $this->eventItem($event));
        $fallbacks = AuditResult::query()
            ->select([
                'id',
                'audit_rule_id',
                'nation_id',
                'first_detected_at',
                'last_evaluated_at',
                'acknowledged_at',
                'snoozed_until',
                'waived_until',
                'created_at',
            ])
            ->where('nation_id', $nation->id)
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('audit_result_events')
                    ->whereColumn('audit_result_events.audit_result_id', 'audit_results.id');
            })
            ->latest('first_detected_at')
            ->limit($recordLimit)
            ->get()
            ->map(fn (AuditResult $result): MemberTimelineItem => $this->fallbackItem($result));

        return $events->concat($fallbacks)->values();
    }

    private function eventItem(AuditResultEvent $event): MemberTimelineItem
    {
        $presentation = $this->eventPresentation((string) $event->event_type);
        $actorKind = $event->actor === null
            ? 'system'
            : ((bool) $event->actor->is_admin ? 'staff' : 'member');

        return new MemberTimelineItem(
            sourceKey: "audit-event:{$event->id}",
            deduplicationKey: "audit-event:{$event->id}",
            category: $this->category(),
            occurredAt: CarbonImmutable::instance($event->occurred_at),
            actorKind: $actorKind,
            actorLabel: $event->actor?->name ?? 'Audit evaluator',
            summary: $presentation['summary'],
            statusLabel: $presentation['label'],
            statusIntent: $presentation['intent'],
            statusIcon: $presentation['icon'],
            sourceUrl: $event->audit_rule_id !== null
                ? route('admin.audits.rules.violations', ['auditRule' => $event->audit_rule_id])
                : route('admin.audits.index'),
            sourceLabel: $event->audit_result_id !== null
                ? "audit finding #{$event->audit_result_id}"
                : "audit event #{$event->id}",
            sourcePriority: 100,
        );
    }

    private function fallbackItem(AuditResult $result): MemberTimelineItem
    {
        $presentation = match (true) {
            $result->waived_until?->isFuture() === true => ['label' => 'Waived', 'intent' => 'warning', 'icon' => 'exclamation-triangle'],
            $result->snoozed_until?->isFuture() === true => ['label' => 'Snoozed', 'intent' => 'pending', 'icon' => 'clock'],
            $result->acknowledged_at !== null => ['label' => 'Acknowledged', 'intent' => 'active', 'icon' => 'check-circle'],
            default => ['label' => 'Open', 'intent' => 'failure', 'icon' => 'exclamation-triangle'],
        };

        return new MemberTimelineItem(
            sourceKey: "audit-result:{$result->id}:current",
            deduplicationKey: "audit-result:{$result->id}:current",
            category: $this->category(),
            occurredAt: CarbonImmutable::instance($result->first_detected_at ?? $result->created_at),
            actorKind: 'system',
            actorLabel: 'Audit evaluator',
            summary: 'Retained audit finding is currently recorded.',
            statusLabel: $presentation['label'],
            statusIntent: $presentation['intent'],
            statusIcon: $presentation['icon'],
            sourceUrl: route('admin.audits.rules.violations', ['auditRule' => $result->audit_rule_id]),
            sourceLabel: "audit finding #{$result->id}",
            sourcePriority: 20,
        );
    }

    /** @return array{summary: string, label: string, intent: string, icon: string} */
    private function eventPresentation(string $eventType): array
    {
        return match ($eventType) {
            'opened' => ['summary' => 'Audit finding opened.', 'label' => 'Opened', 'intent' => 'failure', 'icon' => 'exclamation-triangle'],
            'resolved' => ['summary' => 'Audit finding resolved.', 'label' => 'Resolved', 'intent' => 'success', 'icon' => 'check-circle'],
            'acknowledged' => ['summary' => 'Audit finding acknowledged.', 'label' => 'Acknowledged', 'intent' => 'active', 'icon' => 'check-circle'],
            'snoozed' => ['summary' => 'Audit finding snoozed.', 'label' => 'Snoozed', 'intent' => 'pending', 'icon' => 'clock'],
            'waived' => ['summary' => 'Audit finding waiver recorded.', 'label' => 'Waived', 'intent' => 'warning', 'icon' => 'exclamation-triangle'],
            'rule_revised' => ['summary' => 'Audit finding reevaluated after a rule revision.', 'label' => 'Reevaluated', 'intent' => 'active', 'icon' => 'arrow-path'],
            'rule_disabled' => ['summary' => 'Audit finding closed after its rule was disabled.', 'label' => 'Closed', 'intent' => 'neutral', 'icon' => 'archive-box'],
            'admin_updated' => ['summary' => 'Audit remediation schedule updated.', 'label' => 'Updated', 'intent' => 'active', 'icon' => 'pencil-square'],
            default => ['summary' => 'Audit finding lifecycle updated.', 'label' => 'Updated', 'intent' => 'neutral', 'icon' => 'arrow-path'],
        };
    }
}

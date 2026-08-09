<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Models\AuditResult;
use App\Services\StaffWorkQueue\ProvidesStaffWorkQueueSourceV2;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceV2;

final class AuditRemediationWorkQueueSource implements StaffWorkQueueSourceV2
{
    use ProvidesStaffWorkQueueSourceV2;

    public function type(): string
    {
        return 'audit_remediation';
    }

    public function label(): string
    {
        return 'Audit remediation';
    }

    public function ability(): string
    {
        return 'manage-audits';
    }

    public function load(): array
    {
        return AuditResult::query()
            ->where(fn ($query) => $query->whereNull('waived_until')->orWhere('waived_until', '<=', now()))
            ->where(fn ($query) => $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->with([
                'rule:id,name,priority',
                'nation:id,leader_name,nation_name',
                'city:id,name,nation_id',
            ])
            ->oldest('first_detected_at')
            ->get()
            ->map(function (AuditResult $result): StaffWorkItem {
                $target = $result->city?->name
                    ?: ($result->nation?->leader_name ?: 'Nation #'.$result->nation_id);
                $rule = $result->rule?->name ?: 'Audit rule #'.$result->audit_rule_id;
                $priority = $result->rule?->priority?->value ?? 'low';
                $targetSearch = $result->nation?->leader_name ?: $result->city?->name;

                return new StaffWorkItem(
                    type: $this->type(),
                    id: $result->getKey(),
                    typeLabel: 'Audit remediation',
                    subject: $target.' — '.$rule,
                    createdAt: $result->first_detected_at ?? $result->created_at,
                    ownerKey: null,
                    ownerLabel: null,
                    statusLabel: $result->acknowledged_at ? 'Acknowledged finding' : 'Open finding',
                    statusIntent: $result->acknowledged_at ? 'active' : 'warning',
                    statusIcon: $result->acknowledged_at ? 'eye' : 'exclamation-triangle',
                    nextActionLabel: 'Review remediation',
                    url: $result->rule
                        ? route('admin.audits.rules.violations', [
                            'auditRule' => $result->audit_rule_id,
                            'target' => $targetSearch,
                            'work_item' => $this->type().':'.$result->getKey(),
                        ])
                        : route('admin.audits.index'),
                    dueAt: $result->due_at,
                    urgencyHint: match ($priority) {
                        'high' => 'urgent',
                        'medium' => 'attention',
                        default => null,
                    },
                    searchTerms: [
                        (string) $result->nation_id,
                        (string) ($result->nation?->nation_name ?? ''),
                        $priority,
                    ],
                );
            })
            ->all();
    }
}

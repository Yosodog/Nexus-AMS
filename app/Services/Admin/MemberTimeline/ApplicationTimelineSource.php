<?php

namespace App\Services\Admin\MemberTimeline;

use App\DataTransferObjects\Admin\MemberTimelineItem;
use App\Enums\ApplicationStatus;
use App\Enums\MemberTimelineCategory;
use App\Models\Application;
use App\Models\Nation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ApplicationTimelineSource implements MemberTimelineSource
{
    public function category(): MemberTimelineCategory
    {
        return MemberTimelineCategory::Applications;
    }

    public function visibleTo(User $viewer): bool
    {
        return $viewer->can('view-applications');
    }

    public function items(Nation $nation, User $viewer, int $recordLimit): Collection
    {
        return Application::query()
            ->select([
                'id',
                'nation_id',
                'leader_name_snapshot',
                'status',
                'approved_at',
                'denied_at',
                'cancelled_at',
                'created_at',
            ])
            ->where('nation_id', $nation->id)
            ->latest('created_at')
            ->limit($recordLimit)
            ->get()
            ->flatMap(fn (Application $application): array => $this->applicationItems($application))
            ->values();
    }

    /** @return list<MemberTimelineItem> */
    private function applicationItems(Application $application): array
    {
        $url = route('admin.applications.show', ['application' => $application->id]);
        $items = [new MemberTimelineItem(
            sourceKey: "application:{$application->id}:submitted",
            deduplicationKey: "application:{$application->id}:submitted",
            category: $this->category(),
            occurredAt: CarbonImmutable::instance($application->created_at),
            actorKind: 'member',
            actorLabel: $application->leader_name_snapshot,
            summary: 'Membership application submitted.',
            statusLabel: 'Submitted',
            statusIntent: 'pending',
            statusIcon: 'clock',
            sourceUrl: $url,
            sourceLabel: "application #{$application->id}",
        )];
        $decisionAt = match ($application->status) {
            ApplicationStatus::Approved => $application->approved_at,
            ApplicationStatus::Denied => $application->denied_at,
            ApplicationStatus::Cancelled => $application->cancelled_at,
            default => null,
        };

        if ($decisionAt === null) {
            return $items;
        }

        $presentation = $application->status->presentation();
        $items[] = new MemberTimelineItem(
            sourceKey: "application:{$application->id}:decision:{$application->status->value}",
            deduplicationKey: "application:{$application->id}:decision",
            category: $this->category(),
            occurredAt: CarbonImmutable::instance($decisionAt),
            actorKind: $application->status === ApplicationStatus::Cancelled ? 'system' : 'staff',
            actorLabel: $application->status === ApplicationStatus::Cancelled
                ? 'Application'
                : 'Application review team',
            summary: "Membership application {$presentation['label']}.",
            statusLabel: $presentation['label'],
            statusIntent: $presentation['intent'],
            statusIcon: $presentation['icon'],
            sourceUrl: $url,
            sourceLabel: "application #{$application->id}",
            sourcePriority: 70,
        );

        return $items;
    }
}

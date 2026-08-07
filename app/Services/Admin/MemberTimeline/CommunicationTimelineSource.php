<?php

namespace App\Services\Admin\MemberTimeline;

use App\DataTransferObjects\Admin\MemberTimelineItem;
use App\Enums\MemberTimelineCategory;
use App\Models\ApplicationMessage;
use App\Models\Nation;
use App\Models\RecruitedNation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class CommunicationTimelineSource implements MemberTimelineSource
{
    public function category(): MemberTimelineCategory
    {
        return MemberTimelineCategory::Communications;
    }

    public function visibleTo(User $viewer): bool
    {
        return $viewer->can('view-applications') || $viewer->can('view-recruitment');
    }

    public function items(Nation $nation, User $viewer, int $recordLimit): Collection
    {
        $items = collect();

        if ($viewer->can('view-applications')) {
            $items->push(...$this->applicationMessageItems($nation, $recordLimit));
        }

        if ($viewer->can('view-recruitment')) {
            $items->push(...$this->recruitmentItems($nation, $recordLimit));
        }

        return $items;
    }

    /** @return Collection<int, MemberTimelineItem> */
    private function applicationMessageItems(Nation $nation, int $recordLimit): Collection
    {
        return ApplicationMessage::query()
            ->select([
                'application_messages.id',
                'application_messages.application_id',
                'application_messages.discord_username',
                'application_messages.is_staff',
                'application_messages.sent_at',
            ])
            ->join('applications', 'applications.id', '=', 'application_messages.application_id')
            ->where('applications.nation_id', $nation->id)
            ->latest('application_messages.sent_at')
            ->limit($recordLimit)
            ->get()
            ->map(fn (ApplicationMessage $message): MemberTimelineItem => new MemberTimelineItem(
                sourceKey: "application-message:{$message->id}",
                deduplicationKey: "application-message:{$message->id}",
                category: $this->category(),
                occurredAt: CarbonImmutable::instance($message->sent_at),
                actorKind: $message->is_staff ? 'staff' : 'member',
                actorLabel: $message->discord_username,
                summary: $message->is_staff
                    ? 'Staff replied in the application conversation.'
                    : 'Applicant replied in the application conversation.',
                statusLabel: 'Recorded',
                statusIntent: 'success',
                statusIcon: 'check-circle',
                sourceUrl: route('admin.applications.show', ['application' => $message->application_id]),
                sourceLabel: "application #{$message->application_id}",
                sourcePriority: 60,
            ));
    }

    /** @return Collection<int, MemberTimelineItem> */
    private function recruitmentItems(Nation $nation, int $recordLimit): Collection
    {
        return RecruitedNation::query()
            ->select(['id', 'nation_id', 'primary_sent_at', 'follow_up_sent_at'])
            ->where('nation_id', $nation->id)
            ->latest('updated_at')
            ->limit($recordLimit)
            ->get()
            ->flatMap(function (RecruitedNation $record): array {
                $items = [];

                if ($record->primary_sent_at !== null) {
                    $items[] = new MemberTimelineItem(
                        sourceKey: "recruitment:{$record->id}:primary-sent",
                        deduplicationKey: "recruitment:{$record->id}:primary-sent",
                        category: $this->category(),
                        occurredAt: CarbonImmutable::instance($record->primary_sent_at),
                        actorKind: 'system',
                        actorLabel: 'Recruitment automation',
                        summary: 'Primary recruitment message sent.',
                        statusLabel: 'Sent',
                        statusIntent: 'success',
                        statusIcon: 'paper-airplane',
                        sourceUrl: route('admin.recruitment.index'),
                        sourceLabel: 'recruitment activity',
                    );
                }

                if ($record->follow_up_sent_at !== null) {
                    $items[] = new MemberTimelineItem(
                        sourceKey: "recruitment:{$record->id}:follow-up-sent",
                        deduplicationKey: "recruitment:{$record->id}:follow-up-sent",
                        category: $this->category(),
                        occurredAt: CarbonImmutable::instance($record->follow_up_sent_at),
                        actorKind: 'system',
                        actorLabel: 'Recruitment automation',
                        summary: 'Recruitment follow-up message sent.',
                        statusLabel: 'Sent',
                        statusIntent: 'success',
                        statusIcon: 'paper-airplane',
                        sourceUrl: route('admin.recruitment.index'),
                        sourceLabel: 'recruitment activity',
                    );
                }

                return $items;
            })
            ->values();
    }
}

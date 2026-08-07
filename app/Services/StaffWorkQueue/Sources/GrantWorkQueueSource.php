<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Models\GrantApplication;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSource;

final class GrantWorkQueueSource implements StaffWorkQueueSource
{
    public function type(): string
    {
        return 'grants';
    }

    public function label(): string
    {
        return 'Custom grants';
    }

    public function ability(): string
    {
        return 'manage-grants';
    }

    public function load(): array
    {
        return GrantApplication::query()
            ->where('status', 'pending')
            ->with([
                'grant:id,name',
                'nation:id,leader_name,nation_name',
            ])
            ->oldest()
            ->get()
            ->map(function (GrantApplication $application): StaffWorkItem {
                $leader = $application->nation?->leader_name ?: 'Nation #'.$application->nation_id;
                $program = $application->program_name_snapshot
                    ?: ($application->grant?->name ?: 'Grant program #'.$application->grant_id);

                return new StaffWorkItem(
                    type: $this->type(),
                    id: $application->getKey(),
                    typeLabel: 'Custom grant',
                    subject: $leader.' — '.$program,
                    createdAt: $application->created_at,
                    ownerKey: null,
                    ownerLabel: null,
                    statusLabel: 'Pending review',
                    statusIntent: 'pending',
                    statusIcon: 'clock',
                    nextActionLabel: 'Review grant',
                    url: route('admin.grants', ['work_item' => $this->type().':'.$application->getKey()]),
                    searchTerms: [
                        (string) $application->nation_id,
                        (string) ($application->nation?->nation_name ?? ''),
                        $program,
                    ],
                );
            })
            ->all();
    }
}

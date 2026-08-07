<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSource;

final class ApplicationWorkQueueSource implements StaffWorkQueueSource
{
    public function type(): string
    {
        return 'applications';
    }

    public function label(): string
    {
        return 'Applications';
    }

    public function ability(): string
    {
        return 'manage-applications';
    }

    public function load(): array
    {
        return Application::query()
            ->where('status', ApplicationStatus::Pending->value)
            ->oldest()
            ->get()
            ->map(fn (Application $application): StaffWorkItem => new StaffWorkItem(
                type: $this->type(),
                id: $application->getKey(),
                typeLabel: 'Membership application',
                subject: $application->leader_name_snapshot.' (Nation #'.$application->nation_id.')',
                createdAt: $application->created_at,
                ownerKey: null,
                ownerLabel: null,
                statusLabel: 'Pending review',
                statusIntent: 'pending',
                statusIcon: 'clock',
                nextActionLabel: 'Review application',
                url: route('admin.applications.show', $application),
                searchTerms: [
                    (string) $application->nation_id,
                    (string) $application->discord_username,
                    (string) $application->discord_user_id,
                ],
            ))
            ->all();
    }
}

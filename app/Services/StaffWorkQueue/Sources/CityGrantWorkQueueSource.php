<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Models\CityGrantRequest;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSource;

final class CityGrantWorkQueueSource implements StaffWorkQueueSource
{
    public function type(): string
    {
        return 'city_grants';
    }

    public function label(): string
    {
        return 'City grants';
    }

    public function ability(): string
    {
        return 'manage-city-grants';
    }

    public function load(): array
    {
        return CityGrantRequest::query()
            ->where('status', 'pending')
            ->with('nation:id,leader_name,nation_name')
            ->oldest()
            ->get()
            ->map(function (CityGrantRequest $request): StaffWorkItem {
                $leader = $request->nation?->leader_name ?: 'Nation #'.$request->nation_id;

                return new StaffWorkItem(
                    type: $this->type(),
                    id: $request->getKey(),
                    typeLabel: 'City grant',
                    subject: $leader.' — City '.$request->city_number,
                    createdAt: $request->created_at,
                    ownerKey: null,
                    ownerLabel: null,
                    statusLabel: 'Pending review',
                    statusIntent: 'pending',
                    statusIcon: 'clock',
                    nextActionLabel: 'Review city grant',
                    url: route('admin.grants.city', ['work_item' => $this->type().':'.$request->getKey()]),
                    searchTerms: [
                        (string) $request->nation_id,
                        (string) ($request->nation?->nation_name ?? ''),
                        (string) $request->city_number,
                    ],
                );
            })
            ->all();
    }
}

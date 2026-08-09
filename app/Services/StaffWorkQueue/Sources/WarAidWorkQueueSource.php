<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Models\WarAidRequest;
use App\Services\StaffWorkQueue\ProvidesStaffWorkQueueSourceV2;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceV2;

final class WarAidWorkQueueSource implements StaffWorkQueueSourceV2
{
    use ProvidesStaffWorkQueueSourceV2;

    public function type(): string
    {
        return 'war_aid';
    }

    public function label(): string
    {
        return 'War aid';
    }

    public function ability(): string
    {
        return 'manage-war-aid';
    }

    public function load(): array
    {
        return WarAidRequest::query()
            ->where('status', 'pending')
            ->with('nation:id,leader_name,nation_name')
            ->oldest()
            ->get()
            ->map(function (WarAidRequest $request): StaffWorkItem {
                $leader = $request->nation?->leader_name ?: 'Nation #'.$request->nation_id;

                return new StaffWorkItem(
                    type: $this->type(),
                    id: $request->getKey(),
                    typeLabel: 'War aid',
                    subject: $leader.' — $'.number_format((float) $request->money, 2),
                    createdAt: $request->created_at,
                    ownerKey: null,
                    ownerLabel: null,
                    statusLabel: 'Pending review',
                    statusIntent: 'pending',
                    statusIcon: 'clock',
                    nextActionLabel: 'Review war aid',
                    url: route('admin.war-aid', ['work_item' => $this->type().':'.$request->getKey()]),
                    searchTerms: [
                        (string) $request->nation_id,
                        (string) ($request->nation?->nation_name ?? ''),
                        (string) ($request->note ?? ''),
                    ],
                );
            })
            ->all();
    }
}

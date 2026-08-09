<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Models\RebuildingRequest;
use App\Services\RebuildingService;
use App\Services\StaffWorkQueue\ProvidesStaffWorkQueueSourceV2;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceV2;

final class RebuildingWorkQueueSource implements StaffWorkQueueSourceV2
{
    use ProvidesStaffWorkQueueSourceV2;

    public function __construct(private readonly RebuildingService $rebuildingService) {}

    public function type(): string
    {
        return 'rebuilding';
    }

    public function label(): string
    {
        return 'Rebuilding';
    }

    public function ability(): string
    {
        return 'manage-rebuilding';
    }

    public function load(): array
    {
        return RebuildingRequest::query()
            ->where('cycle_id', $this->rebuildingService->getCurrentCycleId())
            ->where('status', 'pending')
            ->with([
                'nation:id,leader_name,nation_name',
                'tier:id,name',
            ])
            ->oldest()
            ->get()
            ->map(function (RebuildingRequest $request): StaffWorkItem {
                $leader = $request->nation?->leader_name ?: 'Nation #'.$request->nation_id;

                return new StaffWorkItem(
                    type: $this->type(),
                    id: $request->getKey(),
                    typeLabel: 'Rebuilding request',
                    subject: $leader.' · $'.number_format((float) $request->estimated_amount, 2),
                    createdAt: $request->created_at,
                    ownerKey: null,
                    ownerLabel: null,
                    statusLabel: 'Pending review',
                    statusIntent: 'pending',
                    statusIcon: 'clock',
                    nextActionLabel: 'Review rebuilding',
                    url: route('admin.rebuilding.index', ['work_item' => $this->type().':'.$request->getKey()]),
                    searchTerms: [
                        (string) $request->nation_id,
                        (string) ($request->nation?->nation_name ?? ''),
                        (string) ($request->tier?->name ?? ''),
                        (string) ($request->note ?? ''),
                    ],
                );
            })
            ->all();
    }
}

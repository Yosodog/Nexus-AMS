<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Enums\BlockadeReliefStatus;
use App\Models\BlockadeReliefRequest;
use App\Services\StaffWorkQueue\ProvidesStaffWorkQueueSourceV2;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceV2;

final class BlockadeReliefWorkQueueSource implements StaffWorkQueueSourceV2
{
    use ProvidesStaffWorkQueueSourceV2;

    public function type(): string
    {
        return 'blockade_relief';
    }

    public function label(): string
    {
        return 'Blockade relief';
    }

    public function ability(): string
    {
        return 'manage-war-room';
    }

    public function load(): array
    {
        return BlockadeReliefRequest::query()
            ->whereIn('status', [BlockadeReliefStatus::Pending->value, BlockadeReliefStatus::Claimed->value])
            ->with([
                'requester:id,leader_name,nation_name',
                'blockadingNation:id,leader_name,nation_name',
                'claimer:id,leader_name,nation_name',
            ])
            ->oldest()
            ->get()
            ->map(function (BlockadeReliefRequest $request): StaffWorkItem {
                $requester = $request->requester?->leader_name ?: 'Nation #'.$request->requester_nation_id;
                $blockader = $request->blockadingNation?->leader_name ?: 'Nation #'.$request->blockading_nation_id;
                $claimer = $request->claimer?->leader_name;
                $isClaimed = $request->status === BlockadeReliefStatus::Claimed;

                return new StaffWorkItem(
                    type: $this->type(),
                    id: $request->getKey(),
                    typeLabel: 'Blockade relief',
                    subject: $requester.' blocked by '.$blockader,
                    createdAt: $request->created_at,
                    ownerKey: $request->claimed_by_nation_id ? 'nation:'.$request->claimed_by_nation_id : null,
                    ownerLabel: $request->claimed_by_nation_id ? ($claimer ?: 'Nation #'.$request->claimed_by_nation_id) : null,
                    statusLabel: $isClaimed ? 'Relief claimed' : 'Awaiting claimant',
                    statusIntent: $isClaimed ? 'active' : 'warning',
                    statusIcon: $isClaimed ? 'eye' : 'exclamation-triangle',
                    nextActionLabel: 'Coordinate relief',
                    url: route('defense.blockade-relief', ['work_item' => $this->type().':'.$request->getKey()]),
                    dueAt: $request->deadline_at,
                    urgencyHint: $isClaimed ? 'attention' : 'urgent',
                    searchTerms: [
                        (string) $request->war_id,
                        (string) $request->requester_nation_id,
                        (string) $request->blockading_nation_id,
                        (string) ($request->note ?? ''),
                    ],
                );
            })
            ->all();
    }
}

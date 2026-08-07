<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Models\MemberTransfer;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSource;

final class MemberTransferWorkQueueSource implements StaffWorkQueueSource
{
    public function type(): string
    {
        return 'member_transfers';
    }

    public function label(): string
    {
        return 'Member transfers';
    }

    public function ability(): string
    {
        return 'manage-accounts';
    }

    public function load(): array
    {
        return MemberTransfer::query()
            ->where('status', MemberTransfer::STATUS_PENDING)
            ->with([
                'fromNation:id,leader_name,nation_name',
                'toNation:id,leader_name,nation_name',
                'fromAccount:id,nation_id',
            ])
            ->oldest()
            ->get()
            ->map(function (MemberTransfer $transfer): StaffWorkItem {
                $from = $transfer->fromNation?->leader_name ?: 'Nation #'.$transfer->from_nation_id;
                $to = $transfer->toNation?->leader_name ?: 'Nation #'.$transfer->to_nation_id;

                return new StaffWorkItem(
                    type: $this->type(),
                    id: $transfer->getKey(),
                    typeLabel: 'Member transfer',
                    subject: $from.' → '.$to,
                    createdAt: $transfer->created_at,
                    ownerKey: 'nation:'.$transfer->to_nation_id,
                    ownerLabel: $to,
                    statusLabel: 'Awaiting recipient',
                    statusIntent: 'active',
                    statusIcon: 'clock',
                    nextActionLabel: 'Review transfer',
                    url: route('admin.member-transfers.show', $transfer),
                    searchTerms: [
                        (string) $transfer->from_nation_id,
                        (string) $transfer->to_nation_id,
                        (string) ($transfer->fromNation?->nation_name ?? ''),
                        (string) ($transfer->toNation?->nation_name ?? ''),
                    ],
                );
            })
            ->all();
    }
}

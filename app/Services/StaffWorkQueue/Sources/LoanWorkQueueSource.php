<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Services\StaffWorkQueue\ProvidesStaffWorkQueueSourceV2;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSourceV2;

final class LoanWorkQueueSource implements StaffWorkQueueSourceV2
{
    use ProvidesStaffWorkQueueSourceV2;

    public function type(): string
    {
        return 'loans';
    }

    public function label(): string
    {
        return 'Loans';
    }

    public function ability(): string
    {
        return 'manage-loans';
    }

    public function load(): array
    {
        return Loan::query()
            ->where('status', LoanStatus::Pending->value)
            ->with('nation:id,leader_name,nation_name')
            ->oldest()
            ->get()
            ->map(function (Loan $loan): StaffWorkItem {
                $leader = $loan->nation?->leader_name ?: 'Nation #'.$loan->nation_id;

                return new StaffWorkItem(
                    type: $this->type(),
                    id: $loan->getKey(),
                    typeLabel: 'Loan application',
                    subject: $leader.' · $'.number_format((float) $loan->amount, 2),
                    createdAt: $loan->created_at,
                    ownerKey: null,
                    ownerLabel: null,
                    statusLabel: 'Pending review',
                    statusIntent: 'pending',
                    statusIcon: 'clock',
                    nextActionLabel: 'Review loan',
                    url: route('admin.loans.view', ['Loan' => $loan->getKey()]),
                    searchTerms: [
                        (string) $loan->nation_id,
                        (string) ($loan->nation?->nation_name ?? ''),
                        (string) $loan->amount,
                    ],
                );
            })
            ->all();
    }
}

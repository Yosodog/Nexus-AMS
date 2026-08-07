<?php

namespace App\Services\StaffWorkQueue\Sources;

use App\Models\Transaction;
use App\Services\StaffWorkQueue\StaffWorkItem;
use App\Services\StaffWorkQueue\StaffWorkQueueSource;

final class WithdrawalWorkQueueSource implements StaffWorkQueueSource
{
    public function type(): string
    {
        return 'withdrawals';
    }

    public function label(): string
    {
        return 'Withdrawals';
    }

    public function ability(): string
    {
        return 'manage-accounts';
    }

    public function load(): array
    {
        return Transaction::query()
            ->where('is_pending', true)
            ->where('transaction_type', 'withdrawal')
            ->whereNull('to_account_id')
            ->with([
                'nation:id,leader_name,nation_name',
                'fromAccount:id,nation_id',
            ])
            ->oldest()
            ->get()
            ->map(function (Transaction $transaction): StaffWorkItem {
                $leader = $transaction->nation?->leader_name ?: 'Nation #'.$transaction->nation_id;
                $url = $transaction->from_account_id
                    ? route('admin.accounts.view', [
                        'accounts' => $transaction->from_account_id,
                        'transaction' => $transaction->getKey(),
                    ])
                    : route('admin.accounts.dashboard', ['transaction' => $transaction->getKey()]);
                $requiresReconciliation = $transaction->bank_attempt_status === Transaction::BANK_ATTEMPT_NEEDS_RECONCILIATION;
                $requiresReview = (bool) $transaction->requires_admin_approval;

                return new StaffWorkItem(
                    type: $this->type(),
                    id: $transaction->getKey(),
                    typeLabel: 'Withdrawal',
                    subject: $leader.' — $'.number_format((float) $transaction->money, 2),
                    createdAt: $transaction->created_at,
                    ownerKey: null,
                    ownerLabel: null,
                    statusLabel: match (true) {
                        $requiresReconciliation => 'Needs reconciliation',
                        $requiresReview => 'Pending review',
                        default => 'Awaiting fulfillment',
                    },
                    statusIntent: $requiresReconciliation ? 'warning' : ($requiresReview ? 'pending' : 'active'),
                    statusIcon: $requiresReconciliation ? 'exclamation-triangle' : 'clock',
                    nextActionLabel: match (true) {
                        $requiresReconciliation => 'Reconcile withdrawal',
                        $requiresReview => 'Review withdrawal',
                        default => 'Inspect withdrawal',
                    },
                    url: $url,
                    urgencyHint: $requiresReconciliation ? 'urgent' : null,
                    searchTerms: [
                        (string) $transaction->nation_id,
                        (string) ($transaction->nation?->nation_name ?? ''),
                        (string) ($transaction->bank_correlation_id ?? ''),
                        (string) ($transaction->note ?? ''),
                    ],
                );
            })
            ->all();
    }
}

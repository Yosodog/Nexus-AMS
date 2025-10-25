<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WithdrawLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WithdrawalLimitService
{
    public static function limits(): Collection
    {
        return WithdrawLimit::query()->get()->keyBy('resource');
    }

    public static function evaluate(int $nationId, array $requestedResources): array
    {
        $limits = self::limits();
        $transactions = Transaction::query()
            ->where('nation_id', $nationId)
            ->where('transaction_type', 'withdrawal')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->where('requires_admin_approval', false)
            ->get();

        $exceededResources = [];
        foreach (PWHelperService::resources() as $resource) {
            $limitValue = $limits[$resource]->daily_limit ?? null;
            $requested = (float)($requestedResources[$resource] ?? 0);
            if (is_null($limitValue) || $limitValue <= 0 || $requested <= 0) {
                continue;
            }

            $currentTotal = (float)$transactions->sum($resource);
            if (($currentTotal + $requested) > $limitValue + 0.00001) {
                $exceededResources[] = $resource;
            }
        }

        $maxDailyWithdrawals = SettingService::getWithdrawMaxDailyCount();
        $dailyCount = $transactions->count();
        $countLimitReached = $maxDailyWithdrawals > 0 && $dailyCount >= $maxDailyWithdrawals;

        $requiresApproval = $countLimitReached || !empty($exceededResources);

        $pendingReason = null;
        if ($requiresApproval) {
            $messages = [];
            if (!empty($exceededResources)) {
                $messages[] = 'Exceeded daily limit for ' . collect($exceededResources)
                        ->map(fn(string $resource) => ucfirst($resource))
                        ->implode(', ');
            }

            if ($countLimitReached) {
                $messages[] = 'Reached the maximum automatic withdrawals for the day';
            }

            $pendingReason = implode(' and ', $messages);
        }

        return [
            'requires_approval' => $requiresApproval,
            'exceeded_resources' => $exceededResources,
            'count_limit_reached' => $countLimitReached,
            'pending_reason' => $pendingReason,
        ];
    }
}

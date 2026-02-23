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
        $resources = PWHelperService::resources();
        $sumClauses = collect($resources)
            ->map(fn (string $resource) => "COALESCE(SUM(`{$resource}`), 0) AS `{$resource}`")
            ->implode(', ');

        $totals = Transaction::query()
            ->selectRaw("COUNT(*) AS daily_count, {$sumClauses}")
            ->where('nation_id', $nationId)
            ->where('transaction_type', 'withdrawal')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->where('requires_admin_approval', false)
            ->first();

        $exceededResources = [];
        foreach ($resources as $resource) {
            $limitValue = $limits[$resource]->daily_limit ?? null;
            $requested = (float) ($requestedResources[$resource] ?? 0);
            if (is_null($limitValue) || $limitValue <= 0 || $requested <= 0) {
                continue;
            }

            $currentTotal = (float) ($totals?->{$resource} ?? 0);
            if (($currentTotal + $requested) > $limitValue + 0.00001) {
                $exceededResources[] = $resource;
            }
        }

        $maxDailyWithdrawals = SettingService::getWithdrawMaxDailyCount();
        $dailyCount = (int) ($totals?->daily_count ?? 0);
        $countLimitReached = $maxDailyWithdrawals > 0 && $dailyCount >= $maxDailyWithdrawals;

        $requiresApproval = $countLimitReached || ! empty($exceededResources);

        $pendingReason = null;
        if ($requiresApproval) {
            $messages = [];
            if (! empty($exceededResources)) {
                $messages[] = 'Exceeded daily limit for '.collect($exceededResources)
                    ->map(fn (string $resource) => ucfirst($resource))
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

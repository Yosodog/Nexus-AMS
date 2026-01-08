<?php

namespace App\Services;

use App\Models\DirectDepositLog;
use App\Models\Nation;
use App\Models\PayrollMember;
use App\Models\Taxes;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class NationDashboardService
{
    public function __construct(protected MMRService $mmrService, protected PayrollService $payrollService) {}

    /**
     * Get all dashboard data for a given nation.
     */
    public function getDashboardData(Nation $nation): array
    {
        $signIns = $nation->signIns()->latest('created_at')->take(30)->get()->reverse();
        $accountIds = $nation->accounts()->pluck('id');
        $taxes = $this->getRecentTaxes($nation);
        $afterTaxIncomeTotal = $this->getRecentAfterTaxIncomeTotal($nation);
        $payrollSummary = $this->getPayrollSummary($nation, $accountIds);
        $latestSignIn = $signIns->last();
        $evaluation = $latestSignIn
            ? $this->mmrService->evaluate($nation, $latestSignIn)
            : [
                'mmr_score' => 0,
                'resource_breakdown' => [],
                'weights' => $this->mmrService->getResourceWeights(),
                'tier' => null,
                'meets_unit_requirements' => false,
                'meets_resource_requirements' => false,
            ];

        return [
            'latestSignIn' => $latestSignIn,
            'recentTransactions' => $this->getRecentTransactions($accountIds),
            'nationAge' => now()->diffInDays($nation->created_at),
            'scorePerCity' => $nation->num_cities > 0 ? round($nation->score / $nation->num_cities, 2) : 0,
            'taxTotal' => $taxes->sum(fn ($g) => $g->sum('money')),
            'afterTaxIncomeTotal' => $afterTaxIncomeTotal,
            ...$payrollSummary,
            'mmrScore' => $evaluation['mmr_score'] ?? 0,
            'mmrResourceBreakdown' => $evaluation['resource_breakdown'] ?? [],
            'mmrWeights' => $evaluation['weights'] ?? $this->mmrService->getResourceWeights(),
            'mmrTier' => $evaluation['tier'],
            'mmrUnitRequirements' => $evaluation['tier']
                ? $this->mmrService->buildUnitRequirements($evaluation['tier'], $nation->num_cities)
                : [],
            'mmrUnitsMet' => $evaluation['meets_unit_requirements'] ?? false,
            'mmrResourcesMet' => $evaluation['meets_resource_requirements'] ?? false,
            'scoreChart' => $this->buildScoreChart($signIns),
            'militaryChart' => $this->buildMultiSeriesChart($signIns, [
                'fields' => ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'],
                'label' => 'Military',
            ]),
            'resourceHoldingsChart' => $this->buildMultiSeriesChart($signIns, [
                'fields' => ['steel', 'aluminum', 'gasoline', 'munitions', 'uranium', 'food'],
                'label' => 'Resources',
            ]),
            'moneyTaxChart' => $this->buildMoneyTaxChart($taxes),
            'resourceTaxChart' => $this->buildResourceTaxChart(
                ['steel', 'aluminum', 'gasoline', 'munitions', 'uranium', 'food'],
                $taxes
            ),
        ];
    }

    /**
     * Get last 30 days of taxes grouped by Y-m-d date.
     */
    protected function getRecentTaxes(Nation $nation): Collection
    {
        return Taxes::where('sender_id', $nation->id)
            ->where('date', '>=', now()->subDays(30))
            ->orderBy('date')
            ->get()
            ->groupBy(fn ($t) => $t->date->format('Y-m-d'));
    }

    /**
     * Get the most recent account-related transactions.
     */
    protected function getRecentTransactions(Collection $accountIds): Collection
    {
        return Transaction::where(function ($query) use ($accountIds) {
            $query->whereIn('from_account_id', $accountIds)
                ->orWhereIn('to_account_id', $accountIds);
        })
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    /**
     * Get last 30 days of after-tax direct deposits (money only).
     */
    protected function getRecentAfterTaxIncomeTotal(Nation $nation): float
    {
        return (float) DirectDepositLog::where('nation_id', $nation->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('money');
    }

    /**
     * Payroll snapshot for the dashboard.
     *
     * @return array<string, mixed>
     */
    protected function getPayrollSummary(Nation $nation, Collection $accountIds): array
    {
        $member = PayrollMember::query()
            ->with('grade')
            ->where('nation_id', $nation->id)
            ->orderByDesc('is_active')
            ->first();

        $grade = $member?->grade;
        $weeklyAmount = $grade?->weekly_amount ? (string) $grade->weekly_amount : null;
        $dailyAmount = $weeklyAmount ? $this->payrollService->calculateDailyAmount($weeklyAmount) : null;
        $isActive = $member?->is_active && $grade?->is_enabled;

        $recentPayroll = Transaction::query()
            ->where('transaction_type', 'payroll')
            ->where(function ($query) use ($nation, $accountIds) {
                $query->where('nation_id', $nation->id)
                    ->orWhereIn('to_account_id', $accountIds);
            })
            ->latest('created_at')
            ->limit(5)
            ->get();

        $monthTotal = (float) Transaction::query()
            ->where('transaction_type', 'payroll')
            ->where(function ($query) use ($nation, $accountIds) {
                $query->where('nation_id', $nation->id)
                    ->orWhereIn('to_account_id', $accountIds);
            })
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('money');

        return [
            'payrollMember' => $member,
            'payrollGrade' => $grade,
            'payrollDailyAmount' => $dailyAmount,
            'payrollMonthlyTotal' => $monthTotal,
            'payrollRecent' => $recentPayroll,
            'payrollIsActive' => $isActive,
        ];
    }

    /**
     * Score line chart.
     */
    protected function buildScoreChart(Collection $signIns): array
    {
        return [
            'labels' => $this->pluckDates($signIns),
            'data' => $signIns->pluck('score')->toArray(),
        ];
    }

    /**
     * Multi-line chart generator for any set of numeric fields.
     */
    protected function buildMultiSeriesChart(Collection $signIns, array $config): array
    {
        return [
            'labels' => $this->pluckDates($signIns),
            'datasets' => collect($config['fields'])->map(fn ($field) => [
                'label' => ucfirst($field),
                'data' => $signIns->pluck($field)->toArray(),
                'borderColor' => '#'.substr(md5($field), 0, 6),
                'fill' => false,
            ])->toArray(),
        ];
    }

    /**
     * Money tax chart (bar).
     */
    protected function buildMoneyTaxChart(Collection $taxes): array
    {
        return [
            'labels' => $taxes->keys()->toArray(),
            'data' => $taxes->map(fn ($day) => round($day->sum('money'), 2))->toArray(),
        ];
    }

    /**
     * Resource tax stacked bar chart.
     */
    protected function buildResourceTaxChart(array $resources, Collection $taxes): array
    {
        $chart = [
            'labels' => $taxes->keys()->toArray(),
            'resources' => [],
        ];

        foreach ($resources as $res) {
            $chart['resources'][$res] = [
                'label' => ucfirst($res),
                'data' => [],
            ];
        }

        foreach ($taxes as $daily) {
            foreach ($resources as $res) {
                $chart['resources'][$res]['data'][] = round($daily->sum($res), 2);
            }
        }

        return $chart;
    }

    /**ß
     * Convert sign-in timestamps to label strings.
     * @param Collection $signIns
     * @return array
     */
    protected function pluckDates(Collection $signIns): array
    {
        return $signIns->pluck('created_at')->map(fn ($d) => $d->format('M d'))->toArray();
    }
}

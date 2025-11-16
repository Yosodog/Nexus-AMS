<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AllianceFinanceEntry;
use App\Services\Finance\AllianceFinanceService;
use App\Services\Finance\FinanceCategoryRegistry;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AllianceFinanceController extends Controller
{
    /**
     * Display the ledger dashboard.
     */
    public function index(
        Request $request,
        AllianceFinanceService $financeService,
        FinanceCategoryRegistry $categoryRegistry
    ): View {
        Gate::authorize('view-financial-reports');

        $filterBag = $this->resolveFilters($request, $categoryRegistry);
        $from = $filterBag['from'];
        $to = $filterBag['to'];
        $filters = $filterBag['filters'];

        $entries = $financeService->getEntries($from, $to, $filters);
        $dailySummary = $financeService->getDailySummary($from, $to, $filters);
        $categoryBreakdown = $financeService->getDailyCategoryBreakdown($from, $to, $filters);
        $totals = $financeService->getTotals($from, $to, $filters);

        $dateLabels = $this->enumerateDates($from, $to);
        $dailyNet = $this->buildDailyNet($dailySummary, $dateLabels);
        $bestDay = $dailyNet->sortByDesc('net')->first();
        $worstDay = $dailyNet->sortBy('net')->first();

        $entriesByDate = $entries
            ->groupBy(fn (AllianceFinanceEntry $entry) => $entry->date->toDateString())
            ->sortKeysDesc();

        $dailyTotals = $entriesByDate->map(function (Collection $items) {
            $income = (float) $items->where('direction', AllianceFinanceEntry::DIRECTION_INCOME)->sum('money');
            $expense = (float) $items->where('direction', AllianceFinanceEntry::DIRECTION_EXPENSE)->sum('money');

            return [
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
            ];
        });

        $netChart = [
            'labels' => $dateLabels,
            'income' => $dailyNet->map(fn ($row) => $row['income'] ?? 0.0)->values()->all(),
            'expense' => $dailyNet->map(fn ($row) => $row['expense'] ?? 0.0)->values()->all(),
            'net' => $dailyNet->map(fn ($row) => $row['net'] ?? 0.0)->values()->all(),
        ];

        $categoryDatasets = $this->buildCategoryDatasets($categoryBreakdown, $dateLabels, $categoryRegistry);

        $infoCards = $this->buildInfoCards($totals, $bestDay, $worstDay);

        return view('admin.finance.index', [
            'categories' => $categoryRegistry->all(),
            'selectedDirection' => $filterBag['direction'],
            'selectedCategories' => $filterBag['selected_categories'],
            'from' => $from,
            'to' => $to,
            'entriesByDate' => $entriesByDate,
            'dailyTotals' => $dailyTotals,
            'totals' => $totals,
            'netChart' => $netChart,
            'categoryDatasets' => $categoryDatasets,
            'infoCards' => $infoCards,
            'bestDay' => $bestDay,
            'worstDay' => $worstDay,
            'exportUrl' => route('admin.finance.export', $request->query()),
        ]);
    }

    /**
     * Export the filtered ledger to CSV.
     */
    public function exportCsv(
        Request $request,
        AllianceFinanceService $financeService,
        FinanceCategoryRegistry $categoryRegistry
    ): StreamedResponse {
        Gate::authorize('view-financial-reports');

        $filterBag = $this->resolveFilters($request, $categoryRegistry);
        $entries = $financeService->getEntries($filterBag['from'], $filterBag['to'], $filterBag['filters']);

        $filename = sprintf(
            'alliance-finance-ledger_%s_to_%s.csv',
            $filterBag['from']->format('Ymd'),
            $filterBag['to']->format('Ymd')
        );

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = static function () use ($entries): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'Time',
                'Direction',
                'Category',
                'Description',
                'Money',
                'Coal',
                'Oil',
                'Uranium',
                'Iron',
                'Bauxite',
                'Lead',
                'Gasoline',
                'Munitions',
                'Steel',
                'Aluminum',
                'Food',
                'Nation',
                'Account',
                'Source Type',
                'Source ID',
            ]);

            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $entry->date?->toDateString(),
                    optional($entry->created_at)->format('H:i'),
                    ucfirst($entry->direction),
                    $entry->category,
                    $entry->description,
                    $entry->money,
                    $entry->coal,
                    $entry->oil,
                    $entry->uranium,
                    $entry->iron,
                    $entry->bauxite,
                    $entry->lead,
                    $entry->gasoline,
                    $entry->munitions,
                    $entry->steel,
                    $entry->aluminum,
                    $entry->food,
                    $entry->nation?->nation_name,
                    $entry->account?->name,
                    $entry->source_type,
                    $entry->source_id,
                ]);
            }

            fclose($handle);
        };

        return response()->streamDownload($callback, $filename, $headers);
    }

    /**
     * @return array{
     *     from: CarbonInterface,
     *     to: CarbonInterface,
     *     direction: string,
     *     selected_categories: array<int, string>,
     *     filters: array<string, mixed>
     * }
     */
    private function resolveFilters(Request $request, FinanceCategoryRegistry $registry): array
    {
        $fromInput = $request->input('from');
        $toInput = $request->input('to');

        $from = $fromInput ? Carbon::parse($fromInput) : now()->subDays(14);
        $to = $toInput ? Carbon::parse($toInput) : now();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $direction = $request->string('direction')->lower()->value() ?: 'both';
        $normalizedDirection = in_array($direction, ['income', 'expense'], true) ? $direction : 'both';

        $availableCategories = array_keys($registry->all());
        $selectedCategories = array_values(array_intersect(
            $availableCategories,
            Arr::wrap($request->input('categories', []))
        ));

        $filters = [
            'categories' => $selectedCategories,
        ];

        if ($normalizedDirection !== 'both') {
            $filters['direction'] = $normalizedDirection;
        }

        return [
            'from' => $from->copy()->startOfDay(),
            'to' => $to->copy()->endOfDay(),
            'direction' => $normalizedDirection,
            'selected_categories' => $selectedCategories,
            'filters' => $filters,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function enumerateDates(CarbonInterface $from, CarbonInterface $to): array
    {
        $period = CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay());
        $labels = [];

        foreach ($period as $day) {
            $labels[] = $day->toDateString();
        }

        return $labels;
    }

    /**
     * @param  Collection<int, mixed>  $dailySummary
     * @param  array<int, string>  $dateLabels
     */
    private function buildDailyNet(Collection $dailySummary, array $dateLabels): Collection
    {
        $grouped = $dailySummary->groupBy('date');

        return collect($dateLabels)->mapWithKeys(function (string $date) use ($grouped) {
            /** @var Collection<int, mixed> $rows */
            $rows = $grouped->get($date, collect());

            $incomeRow = $rows->firstWhere('direction', AllianceFinanceEntry::DIRECTION_INCOME);
            $expenseRow = $rows->firstWhere('direction', AllianceFinanceEntry::DIRECTION_EXPENSE);

            $income = $incomeRow ? (float) $incomeRow->money : 0.0;
            $expense = $expenseRow ? (float) $expenseRow->money : 0.0;

            return [
                $date => [
                    'date' => $date,
                    'income' => $income,
                    'expense' => $expense,
                    'net' => $income - $expense,
                ],
            ];
        });
    }

    /**
     * @param  Collection<int, mixed>  $categoryBreakdown
     * @param  array<int, string>  $labels
     * @return array<int, array<string, mixed>>
     */
    private function buildCategoryDatasets(
        Collection $categoryBreakdown,
        array $labels,
        FinanceCategoryRegistry $registry
    ): array {
        $grouped = $categoryBreakdown->groupBy('category');
        $datasets = [];

        foreach ($grouped as $category => $rows) {
            $data = collect($labels)->map(function (string $date) use ($rows) {
                $match = $rows->firstWhere('date', $date);

                return $match ? (float) $match->money : 0.0;
            })->all();

            $datasets[] = [
                'key' => $category,
                'label' => $registry->label($category),
                'color' => $registry->color($category),
                'data' => $data,
            ];
        }

        return $datasets;
    }

    /**
     * @param  array{income: float, expense: float, net: float}  $totals
     * @param  array<string, mixed>|null  $bestDay
     * @param  array<string, mixed>|null  $worstDay
     * @return array<int, array<string, mixed>>
     */
    private function buildInfoCards(array $totals, ?array $bestDay, ?array $worstDay): array
    {
        return [
            [
                'title' => 'Total Income',
                'value' => $totals['income'],
                'variant' => 'success',
                'icon' => 'bi bi-arrow-down-left',
            ],
            [
                'title' => 'Total Expenses',
                'value' => $totals['expense'],
                'variant' => 'danger',
                'icon' => 'bi bi-arrow-up-right',
            ],
            [
                'title' => 'Net Position',
                'value' => $totals['net'],
                'variant' => $totals['net'] >= 0 ? 'primary' : 'warning',
                'icon' => 'bi bi-bank',
            ],
            [
                'title' => 'Best Day',
                'value' => $bestDay['net'] ?? 0.0,
                'variant' => 'info',
                'icon' => 'bi bi-emoji-smile',
                'helper' => $bestDay['date'] ?? null,
            ],
            [
                'title' => 'Worst Day',
                'value' => $worstDay['net'] ?? 0.0,
                'variant' => 'secondary',
                'icon' => 'bi bi-emoji-frown',
                'helper' => $worstDay['date'] ?? null,
            ],
        ];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AllianceFinanceLedgerRequest;
use App\Models\AllianceFinanceEntry;
use App\Services\Finance\AllianceFinanceService;
use App\Services\Finance\FinanceCategoryRegistry;
use App\Support\CsvExport;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View as ViewResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AllianceFinanceController extends Controller
{
    /**
     * Display the ledger dashboard.
     */
    public function index(
        AllianceFinanceLedgerRequest $request,
        AllianceFinanceService $financeService,
        FinanceCategoryRegistry $categoryRegistry
    ): View {
        $filterBag = $this->resolveFilters($request, $categoryRegistry);
        $from = $filterBag['from'];
        $to = $filterBag['to'];
        $filters = $filterBag['filters'];

        $dailySummary = $financeService->getDailySummary($from, $to, $filters);
        $categoryBreakdown = $financeService->getDailyCategoryBreakdown($from, $to, $filters);
        $totals = $financeService->getTotals($from, $to, $filters);
        $transactions = $financeService->paginateEntries(
            $from,
            $to,
            $filters,
            $filterBag['sort'],
            $filterBag['sort_direction'],
        );
        $transactions->appends($filterBag['query']);

        $dateLabels = $this->enumerateDates($from, $to);
        $dailyNet = $this->buildDailyNet($dailySummary, $dateLabels);
        $bestDay = $dailyNet->sortByDesc('net')->first();
        $worstDay = $dailyNet->sortBy('net')->first();

        $netChart = [
            'labels' => $dateLabels,
            'income' => $dailyNet->map(fn ($row) => $row['income'] ?? 0.0)->values()->all(),
            'expense' => $dailyNet->map(fn ($row) => $row['expense'] ?? 0.0)->values()->all(),
            'net' => $dailyNet->map(fn ($row) => $row['net'] ?? 0.0)->values()->all(),
        ];

        $categoryDatasets = $this->buildCategoryDatasets($categoryBreakdown, $dateLabels, $categoryRegistry);

        return view('admin.finance.index', [
            'categories' => $categoryRegistry->all(),
            'selectedDirection' => $filterBag['direction'],
            'selectedCategories' => $filterBag['selected_categories'],
            'selectedSearch' => $filterBag['search'],
            'selectedResource' => $filterBag['resource'],
            'resources' => AllianceFinanceService::FILTERABLE_RESOURCES,
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'transactions' => $transactions,
            'netChart' => $netChart,
            'categoryDatasets' => $categoryDatasets,
            'bestDay' => $bestDay,
            'worstDay' => $worstDay,
            'sort' => $filterBag['sort'],
            'sortDirection' => $filterBag['sort_direction'],
            'sortUrls' => $this->sortUrls($filterBag),
            'activeFilters' => $this->activeFilters($filterBag, $categoryRegistry),
            'hasNarrowingFilters' => $this->hasNarrowingFilters($filterBag),
            'exportUrl' => route('admin.finance.export', $filterBag['query']),
        ]);
    }

    public function dayDetails(
        AllianceFinanceLedgerRequest $request,
        string $date,
        AllianceFinanceService $financeService,
        FinanceCategoryRegistry $categoryRegistry
    ): ViewResponse {
        $filterBag = $this->resolveFilters($request, $categoryRegistry);
        $selectedDate = Carbon::parse($date)->startOfDay();
        $entries = $financeService->getEntriesForDate($selectedDate, $filterBag['filters']);

        return view('admin.finance.partials.day-entries', [
            'entries' => $entries,
            'categories' => $categoryRegistry->all(),
        ]);
    }

    /**
     * Export the filtered ledger to CSV.
     */
    public function exportCsv(
        AllianceFinanceLedgerRequest $request,
        AllianceFinanceService $financeService,
        FinanceCategoryRegistry $categoryRegistry
    ): StreamedResponse {
        $filterBag = $this->resolveFilters($request, $categoryRegistry);

        $filename = sprintf(
            'alliance-finance-ledger_%s_to_%s.csv',
            $filterBag['from']->format('Ymd'),
            $filterBag['to']->format('Ymd')
        );

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($financeService, $filterBag): void {
            $handle = fopen('php://output', 'w');

            CsvExport::writeRow($handle, [
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

            foreach ($financeService->streamEntries($filterBag['from'], $filterBag['to'], $filterBag['filters']) as $entry) {
                CsvExport::writeRow($handle, [
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
     *     search: string|null,
     *     resource: string|null,
     *     sort: string,
     *     sort_direction: string,
     *     filters: array<string, mixed>,
     *     query: array<string, mixed>
     * }
     */
    private function resolveFilters(
        AllianceFinanceLedgerRequest $request,
        FinanceCategoryRegistry $registry,
    ): array {
        $validated = $request->validated();
        $fromInput = $validated['from'] ?? null;
        $toInput = $validated['to'] ?? null;

        $from = $fromInput ? Carbon::parse($fromInput) : now()->subDays(14);
        $to = $toInput ? Carbon::parse($toInput) : now();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $direction = (string) ($validated['direction'] ?? 'both');
        $normalizedDirection = in_array($direction, ['income', 'expense'], true) ? $direction : 'both';

        $availableCategories = array_keys($registry->all());
        $selectedCategories = array_values(array_intersect(
            $availableCategories,
            $validated['categories'] ?? [],
        ));
        sort($selectedCategories);

        $search = $validated['search'] ?? null;
        $resource = $validated['resource'] ?? null;
        $sort = (string) ($validated['sort'] ?? 'date');
        $sortDirection = (string) ($validated['sort_direction'] ?? 'desc');

        $filters = [
            'categories' => $selectedCategories,
        ];

        if ($normalizedDirection !== 'both') {
            $filters['direction'] = $normalizedDirection;
        }

        if (is_string($search) && $search !== '') {
            $filters['search'] = $search;
        }

        if (is_string($resource) && in_array($resource, AllianceFinanceService::FILTERABLE_RESOURCES, true)) {
            $filters['resource'] = $resource;
        } else {
            $resource = null;
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $query = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'direction' => $normalizedDirection,
            'sort' => $sort,
            'sort_direction' => $sortDirection,
        ];

        if ($selectedCategories !== []) {
            $query['categories'] = $selectedCategories;
        }

        if (is_string($search) && $search !== '') {
            $query['search'] = $search;
        }

        if ($resource !== null) {
            $query['resource'] = $resource;
        }

        return [
            'from' => $from,
            'to' => $to,
            'direction' => $normalizedDirection,
            'selected_categories' => $selectedCategories,
            'search' => $search,
            'resource' => $resource,
            'sort' => $sort,
            'sort_direction' => $sortDirection,
            'filters' => $filters,
            'query' => $query,
        ];
    }

    /**
     * @param  array<string, mixed>  $filterBag
     * @return array{date: string, amount: string}
     */
    private function sortUrls(array $filterBag): array
    {
        $urls = [];

        foreach (['date', 'amount'] as $column) {
            $query = $filterBag['query'];
            $query['sort'] = $column;
            $query['sort_direction'] = $filterBag['sort'] === $column && $filterBag['sort_direction'] === 'desc'
                ? 'asc'
                : 'desc';

            $urls[$column] = route('admin.finance.index', $query);
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $filterBag
     * @return array<int, array{label: string, url: string}>
     */
    private function activeFilters(array $filterBag, FinanceCategoryRegistry $registry): array
    {
        $activeFilters = [];
        $query = $filterBag['query'];
        $dateQuery = $query;
        unset($dateQuery['from'], $dateQuery['to']);

        $activeFilters[] = [
            'label' => sprintf(
                'Date: %s – %s',
                $filterBag['from']->toFormattedDateString(),
                $filterBag['to']->toFormattedDateString(),
            ),
            'url' => route('admin.finance.index', $dateQuery),
        ];

        if ($filterBag['direction'] !== 'both') {
            $directionQuery = $query;
            $directionQuery['direction'] = 'both';
            $activeFilters[] = [
                'label' => 'Direction: '.ucfirst($filterBag['direction']),
                'url' => route('admin.finance.index', $directionQuery),
            ];
        }

        foreach ($filterBag['selected_categories'] as $category) {
            $categoryQuery = $query;
            $remainingCategories = array_values(array_diff(
                $filterBag['selected_categories'],
                [$category],
            ));

            if ($remainingCategories === []) {
                unset($categoryQuery['categories']);
            } else {
                $categoryQuery['categories'] = $remainingCategories;
            }

            $activeFilters[] = [
                'label' => 'Category: '.$registry->label($category),
                'url' => route('admin.finance.index', $categoryQuery),
            ];
        }

        if ($filterBag['search'] !== null) {
            $searchQuery = $query;
            unset($searchQuery['search']);
            $activeFilters[] = [
                'label' => 'Search: '.$filterBag['search'],
                'url' => route('admin.finance.index', $searchQuery),
            ];
        }

        if ($filterBag['resource'] !== null) {
            $resourceQuery = $query;
            unset($resourceQuery['resource']);
            $activeFilters[] = [
                'label' => 'Resource: '.ucfirst($filterBag['resource']),
                'url' => route('admin.finance.index', $resourceQuery),
            ];
        }

        return $activeFilters;
    }

    /**
     * @param  array<string, mixed>  $filterBag
     */
    private function hasNarrowingFilters(array $filterBag): bool
    {
        return $filterBag['direction'] !== 'both'
            || $filterBag['selected_categories'] !== []
            || $filterBag['search'] !== null
            || $filterBag['resource'] !== null;
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
        $grouped = $dailySummary->groupBy(
            fn ($row): string => $this->dateKey($row->date)
        );

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
            $rowsByDate = $rows->keyBy(
                fn ($row): string => $this->dateKey($row->date)
            );
            $data = collect($labels)->map(function (string $date) use ($rowsByDate) {
                $match = $rowsByDate->get($date);

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

    private function dateKey(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }
}

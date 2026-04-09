@extends('layouts.main')

@section('content')
    @php
        $greeting = match (true) {
            now()->hour < 12 => 'Good morning',
            now()->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
        $accountIds = $nation->accounts->pluck('id')->all();

        $statCards = [
            [
                'title' => 'Direct Deposit',
                'value' => '$' . number_format($afterTaxIncomeTotal ?? 0),
                'desc' => 'Net cash (30 days)',
                'icon' => 'o-banknotes',
                'accent' => 'primary',
            ],
            [
                'title' => 'Total Taxed',
                'value' => '$' . number_format($taxTotal ?? 0),
                'desc' => 'After deposits',
                'icon' => 'o-building-library',
                'accent' => 'accent',
            ],
            [
                'title' => 'Grants Received',
                'value' => '$' . number_format($grantTotal ?? 0),
                'desc' => 'Approved to date',
                'icon' => 'o-gift',
                'accent' => 'success',
            ],
            [
                'title' => 'Loans',
                'value' => '$' . number_format($loanTotal ?? 0),
                'desc' => 'Outstanding',
                'icon' => 'o-credit-card',
                'accent' => 'warning',
            ],
            [
                'title' => 'City Count',
                'value' => $nation->num_cities ?? 0,
                'desc' => 'Built and online',
                'icon' => 'o-building-office-2',
                'accent' => 'info',
            ],
            [
                'title' => 'Last Sync',
                'value' => $latestSignIn?->created_at?->diffForHumans() ?? 'N/A',
                'desc' => 'PW data sync',
                'icon' => 'o-clock',
                'accent' => 'neutral',
            ],
        ];
    @endphp

    <div class="mx-auto max-w-7xl space-y-6 xl:max-w-6xl 2xl:max-w-[1400px]">
        <div class="relative overflow-hidden rounded-3xl border border-base-300/60 bg-base-100 shadow-lg">
            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-primary/10 blur-3xl"></div>
            <div class="pointer-events-none absolute -left-16 bottom-0 h-48 w-48 rounded-full bg-secondary/10 blur-3xl"></div>

            <div class="relative flex flex-col gap-6 p-6 sm:p-8 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-start gap-5">
                    <div class="avatar">
                        <div class="w-[4.5rem] rounded-2xl ring-2 ring-primary/20 ring-offset-2 ring-offset-base-100">
                            <img src="{{ $nation->flag ?? 'https://politicsandwar.com/img/flags/default.png' }}" alt="Nation flag" />
                        </div>
                    </div>

                    <div class="min-w-0 space-y-1.5">
                        <p class="text-xs font-medium uppercase tracking-widest text-base-content/50">{{ $greeting }}</p>
                        <h1 class="break-words text-2xl font-extrabold leading-tight sm:text-3xl">
                            {{ $nation->leader_name }}
                        </h1>
                        <p class="text-base font-medium text-base-content/60">{{ $nation->nation_name }}</p>

                        <div class="flex flex-wrap gap-1.5 pt-1">
                            <span class="badge badge-soft badge-primary badge-sm">{{ $nation->alliance->name ?? 'Unaffiliated' }}</span>
                            <span class="badge badge-soft badge-sm">{{ $nation->num_cities }} cities</span>
                            <span class="badge badge-soft badge-sm">Score {{ number_format($nation->score, 2) }}</span>
                            @if($latestSignIn && $latestSignIn->created_at)
                                <span class="badge badge-ghost badge-sm">Synced {{ $nation->updated_at->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex flex-shrink-0 flex-wrap gap-2 lg:flex-col lg:items-end">
                    <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank"
                       class="btn btn-primary btn-sm gap-1.5 rounded-xl shadow-sm shadow-primary/20">
                        <x-icon name="o-arrow-top-right-on-square" class="size-3.5" />
                        View on P&amp;W
                    </a>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('accounts') }}" class="btn btn-ghost btn-sm rounded-xl">Accounts</a>
                        <a href="{{ route('grants.city') }}" class="btn btn-ghost btn-sm rounded-xl">Grants</a>
                        <a href="{{ route('loans.index') }}" class="btn btn-ghost btn-sm rounded-xl">Loans</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-3xl border border-primary/20 bg-gradient-to-br from-primary/5 via-base-100 to-base-100 p-5 shadow-md sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-bold">MMR Readiness</h2>
                        <span class="badge badge-sm {{ ($mmrScore ?? 0) >= 100 ? 'badge-success' : (($mmrScore ?? 0) >= 50 ? 'badge-warning' : 'badge-error') }}">
                            {{ $mmrScore ?? 0 }}%
                        </span>
                    </div>
                    <p class="text-sm text-base-content/60">Tier {{ $mmrTier->city_count ?? 0 }} requirements</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <div class="flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm
                        {{ $mmrResourcesMet ? 'border-success/30 bg-success/5 text-success' : 'border-warning/30 bg-warning/5 text-warning' }}">
                        <x-icon name="{{ $mmrResourcesMet ? 'o-check-circle' : 'o-exclamation-triangle' }}" class="size-4" />
                        {{ $mmrResourcesMet ? 'Resources met' : 'Resources low' }}
                    </div>
                    <div class="flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm
                        {{ $mmrUnitsMet ? 'border-success/30 bg-success/5 text-success' : 'border-warning/30 bg-warning/5 text-warning' }}">
                        <x-icon name="{{ $mmrUnitsMet ? 'o-check-circle' : 'o-exclamation-triangle' }}" class="size-4" />
                        {{ $mmrUnitsMet ? 'Units ready' : 'Units low' }}
                    </div>
                </div>
            </div>

            <div class="mt-4 overflow-hidden rounded-full bg-base-300/40">
                <div class="h-2.5 rounded-full transition-all duration-700
                    {{ ($mmrScore ?? 0) >= 100 ? 'bg-success' : (($mmrScore ?? 0) >= 50 ? 'bg-warning' : 'bg-error') }}"
                    style="width: {{ min($mmrScore ?? 0, 100) }}%"></div>
            </div>

            @if(!$mmrResourcesMet || !$mmrUnitsMet)
                <p class="mt-3 text-xs text-base-content/50">
                    Top off the gaps in the comparison tables below to hit full compliance for your tier.
                </p>
            @else
                <p class="mt-3 text-xs text-success/80">
                    You are meeting all current MMR expectations. Nice work!
                </p>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($statCards as $card)
                <div class="group relative overflow-hidden rounded-2xl border border-base-300/60 bg-base-100 p-4 shadow-sm transition hover:shadow-md sm:p-5">
                    <div class="pointer-events-none absolute -right-4 -top-4 h-16 w-16 rounded-full bg-{{ $card['accent'] }}/10 blur-2xl transition-transform group-hover:scale-150"></div>
                    <div class="relative">
                        <div class="mb-3 flex items-center gap-2">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-{{ $card['accent'] }}/10">
                                <x-icon name="{{ $card['icon'] }}" class="size-4 text-{{ $card['accent'] }}" />
                            </div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-base-content/50">{{ $card['title'] }}</p>
                        </div>
                        <p class="text-xl font-extrabold sm:text-2xl">{{ $card['value'] }}</p>
                        <p class="mt-0.5 text-xs text-base-content/50">{{ $card['desc'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rounded-2xl border border-base-300/60 bg-base-100 p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-secondary/10">
                        <x-icon name="o-currency-dollar" class="size-5 text-secondary" />
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-bold">Payroll</h3>
                            <span class="badge badge-sm {{ $payrollIsActive ? 'badge-success' : 'badge-ghost' }}">
                                {{ $payrollIsActive ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                        @if($payrollMember && $payrollGrade)
                            <p class="mt-0.5 text-sm font-semibold text-base-content/80">{{ $payrollGrade->name }}</p>
                            <p class="text-xs text-base-content/50">
                                ${{ number_format((float) $payrollGrade->weekly_amount, 2) }}/week &middot;
                                ${{ number_format((float) $payrollDailyAmount, 2) }}/day
                            </p>
                        @else
                            <p class="mt-0.5 text-sm text-base-content/50">You are not enrolled in payroll.</p>
                        @endif
                    </div>
                </div>

                <div class="flex-shrink-0 rounded-2xl border border-base-200 bg-base-200/30 px-5 py-3 text-center">
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-base-content/40">Last 30 days</p>
                    <p class="text-2xl font-extrabold">${{ number_format($payrollMonthlyTotal ?? 0, 2) }}</p>
                </div>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            <x-user.resource-comparison :resources="$mmrResourceBreakdown" :weights="$mmrWeights" />
            <x-user.military-comparison
                :nation="$nation"
                :latestSignIn="$latestSignIn"
                :requirements="$mmrUnitRequirements"
                :meets="$mmrUnitsMet"
            />
        </div>

        <div x-data="{ activeTab: 'score' }" class="rounded-3xl border border-base-300/60 bg-base-100 shadow-md">
            <div class="border-b border-base-200 px-5 pt-5 sm:px-6 sm:pt-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-bold">Nation Charts</h3>
                        <p class="text-sm text-base-content/50">Live snapshots from your synced data.</p>
                    </div>
                </div>

                <div class="mt-4 flex gap-1 overflow-x-auto pb-px">
                    @php
                        $tabs = [
                            'score' => 'Score',
                            'tax' => 'Tax Revenue',
                            'resources' => 'Resource Tax',
                            'military' => 'Military',
                            'holdings' => 'Holdings',
                        ];
                    @endphp
                    @foreach($tabs as $key => $label)
                        <button
                            @click="activeTab = '{{ $key }}'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                            :class="activeTab === '{{ $key }}'
                                ? 'border-primary text-primary font-semibold'
                                : 'border-transparent text-base-content/50 hover:text-base-content/80'"
                            class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition-colors"
                            type="button"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="p-5 sm:p-6">
                <div x-show="activeTab === 'score'" x-transition.opacity>
                    <canvas id="scoreChart" class="w-full" style="height: 280px"></canvas>
                </div>
                <div x-show="activeTab === 'tax'" x-cloak x-transition.opacity>
                    <canvas id="moneyTaxChart" class="w-full" style="height: 280px"></canvas>
                </div>
                <div x-show="activeTab === 'resources'" x-cloak x-transition.opacity>
                    <canvas id="resourceTaxChart" class="w-full" style="height: 280px"></canvas>
                </div>
                <div x-show="activeTab === 'military'" x-cloak x-transition.opacity>
                    <canvas id="militaryChart" class="w-full" style="height: 280px"></canvas>
                </div>
                <div x-show="activeTab === 'holdings'" x-cloak x-transition.opacity>
                    <canvas id="resourceHoldingsChart" class="w-full" style="height: 280px"></canvas>
                </div>
            </div>
        </div>

        <div class="rounded-3xl border border-base-300/60 bg-base-100 shadow-md">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-200 px-5 py-4 sm:px-6">
                <h3 class="text-lg font-bold">Recent Transactions</h3>
                <a href="{{ route('accounts') }}" class="btn btn-ghost btn-sm gap-1 rounded-xl">
                    Open accounts
                    <x-icon name="o-arrow-right" class="size-3.5" />
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                    <tr class="text-xs uppercase tracking-wider text-base-content/40">
                        <th class="font-semibold">Date</th>
                        <th class="font-semibold">Direction</th>
                        <th class="font-semibold">Amount</th>
                        <th class="font-semibold">Note</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($recentTransactions as $tx)
                        @php
                            $direction = in_array($tx->from_account_id, $accountIds, true) ? 'Sent' : 'Received';
                            $isSent = $direction === 'Sent';
                        @endphp
                        <tr class="transition-colors hover:bg-base-200/30">
                            <td class="text-sm text-base-content/70">{{ $tx->created_at->format('M d, Y H:i') }}</td>
                            <td>
                                <span class="inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-xs font-semibold
                                    {{ $isSent ? 'bg-base-200/60 text-base-content/60' : 'bg-success/10 text-success' }}">
                                    <x-icon name="{{ $isSent ? 'o-arrow-up-right' : 'o-arrow-down-left' }}" class="size-3" />
                                    {{ $direction }}
                                </span>
                            </td>
                            <td class="font-bold tabular-nums {{ $isSent ? 'text-base-content/70' : 'text-success' }}">
                                {{ $isSent ? '-' : '+' }}${{ number_format($tx->money, 2) }}
                            </td>
                            <td class="max-w-xs truncate text-sm text-base-content/60">{{ $tx->note ?? 'No note' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-12 text-center">
                                <x-icon name="o-inbox" class="mx-auto size-8 text-base-content/20" />
                                <p class="mt-2 text-sm text-base-content/40">No transactions yet</p>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof Chart === 'undefined') {
                    return;
                }

                const baseFont = {
                    family: "'Inter', 'system-ui', sans-serif",
                    size: 11,
                    weight: 500,
                };

                const rgba = (hex, alpha) => {
                    const normalized = hex.replace('#', '');
                    const value = normalized.length === 3
                        ? normalized.split('').map((char) => char + char).join('')
                        : normalized;
                    const int = Number.parseInt(value, 16);
                    const r = (int >> 16) & 255;
                    const g = (int >> 8) & 255;
                    const b = int & 255;

                    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
                };

                const chartColors = {
                    primary: '#2563eb',
                    success: '#16a34a',
                    info: '#0891b2',
                    warning: '#d97706',
                    danger: '#dc2626',
                    neutral: '#64748b',
                    accent: '#7c3aed',
                    text: '#475569',
                    grid: 'rgba(148, 163, 184, 0.16)',
                    tooltipBackground: 'rgba(15, 23, 42, 0.92)',
                    tooltipBorder: 'rgba(148, 163, 184, 0.22)',
                };

                const gridColor = chartColors.grid;
                const tickColor = chartColors.text;
                const tooltipBackground = chartColors.tooltipBackground;
                const tooltipBorder = chartColors.tooltipBorder;
                const seriesPalette = [
                    chartColors.primary,
                    chartColors.success,
                    chartColors.info,
                    chartColors.warning,
                    chartColors.danger,
                    chartColors.neutral,
                ];

                const commonOptions = {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                boxWidth: 10,
                                color: chartColors.text,
                                font: baseFont,
                                padding: 16,
                                usePointStyle: true,
                            },
                        },
                        tooltip: {
                            backgroundColor: tooltipBackground,
                            titleColor: '#f8fafc',
                            bodyColor: '#e2e8f0',
                            borderColor: tooltipBorder,
                            borderWidth: 1,
                            padding: 10,
                            cornerRadius: 10,
                            boxPadding: 4,
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: tickColor, font: baseFont },
                        },
                        y: {
                            grid: { color: gridColor },
                            ticks: { color: tickColor, font: baseFont },
                            beginAtZero: true,
                        },
                    },
                };

                new Chart(document.getElementById('scoreChart'), {
                    type: 'line',
                    data: {
                        labels: @json($scoreChart['labels']),
                        datasets: [{
                            label: 'Score',
                            data: @json($scoreChart['data']),
                            borderColor: chartColors.primary,
                            backgroundColor: rgba(chartColors.primary, 0.12),
                            fill: true,
                            tension: 0.4,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            borderWidth: 2,
                        }],
                    },
                    options: {
                        ...commonOptions,
                        plugins: {
                            ...commonOptions.plugins,
                            legend: { display: false },
                        },
                    },
                });

                new Chart(document.getElementById('moneyTaxChart'), {
                    type: 'bar',
                    data: {
                        labels: @json($moneyTaxChart['labels']),
                        datasets: [{
                            label: 'Money',
                            data: @json($moneyTaxChart['data']),
                            backgroundColor: rgba(chartColors.success, 0.7),
                            hoverBackgroundColor: rgba(chartColors.success, 0.88),
                            borderRadius: 6,
                            borderSkipped: false,
                        }],
                    },
                    options: {
                        ...commonOptions,
                        plugins: {
                            ...commonOptions.plugins,
                            legend: { display: false },
                        },
                    },
                });

                new Chart(document.getElementById('resourceTaxChart'), {
                    type: 'bar',
                    data: {
                        labels: @json($resourceTaxChart['labels']),
                        datasets: [
                            @foreach ($resourceTaxChart['resources'] as $rData)
                            @php $paletteIndex = $loop->index; @endphp
                            {
                                label: '{{ $rData['label'] }}',
                                data: @json($rData['data']),
                                backgroundColor: rgba(seriesPalette[{{ $paletteIndex }} % seriesPalette.length], 0.82),
                                borderRadius: 4,
                                borderSkipped: false,
                            },
                            @endforeach
                        ],
                    },
                    options: {
                        ...commonOptions,
                        plugins: {
                            ...commonOptions.plugins,
                            tooltip: { ...commonOptions.plugins.tooltip, mode: 'index', intersect: false },
                        },
                        scales: {
                            x: { ...commonOptions.scales.x, stacked: true },
                            y: { ...commonOptions.scales.y, stacked: true },
                        },
                    },
                });

                const militaryDatasets = @json($militaryChart['datasets']);
                militaryDatasets.forEach((dataset, index) => {
                    dataset.borderColor = seriesPalette[index % seriesPalette.length];
                    dataset.backgroundColor = seriesPalette[index % seriesPalette.length];
                    dataset.fill = false;
                    dataset.tension = 0.4;
                    dataset.borderWidth = 2;
                    dataset.pointRadius = 1.5;
                    dataset.pointHoverRadius = 4;
                });

                new Chart(document.getElementById('militaryChart'), {
                    type: 'line',
                    data: {
                        labels: @json($militaryChart['labels']),
                        datasets: militaryDatasets,
                    },
                    options: {
                        ...commonOptions,
                        plugins: {
                            ...commonOptions.plugins,
                            tooltip: { ...commonOptions.plugins.tooltip, mode: 'index', intersect: false },
                        },
                    },
                });

                const holdingsDatasets = @json($resourceHoldingsChart['datasets']);
                holdingsDatasets.forEach((dataset, index) => {
                    dataset.borderColor = seriesPalette[index % seriesPalette.length];
                    dataset.backgroundColor = seriesPalette[index % seriesPalette.length];
                    dataset.fill = false;
                    dataset.tension = 0.4;
                    dataset.borderWidth = 2;
                    dataset.pointRadius = 1.5;
                    dataset.pointHoverRadius = 4;
                });

                new Chart(document.getElementById('resourceHoldingsChart'), {
                    type: 'line',
                    data: {
                        labels: @json($resourceHoldingsChart['labels']),
                        datasets: holdingsDatasets,
                    },
                    options: {
                        ...commonOptions,
                        plugins: {
                            ...commonOptions.plugins,
                            tooltip: { ...commonOptions.plugins.tooltip, mode: 'index', intersect: false },
                        },
                    },
                });
            });
        </script>
    @endpush
@endsection

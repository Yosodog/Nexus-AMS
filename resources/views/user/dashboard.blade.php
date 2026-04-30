@extends('layouts.main')

@section('content')
    @php
        $greeting = match (true) {
            now()->hour < 12 => 'Good morning',
            now()->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        $accountIds = $nation->accounts->pluck('id')->all();
        $allianceName = data_get($nation, 'alliance.name', 'Unaffiliated');
        $scoreHistory = collect($scoreChart['data'] ?? []);
        $scoreDelta = $scoreHistory->count() > 1 ? round((float) $scoreHistory->last() - (float) $scoreHistory->first(), 2) : 0;
        $scorePulse = match (true) {
            $scoreDelta > 0 => '+' . number_format($scoreDelta, 2),
            $scoreDelta < 0 => number_format($scoreDelta, 2),
            default => 'Flat',
        };
        $syncAgeMinutes = $latestSignIn?->created_at?->diffInMinutes(now());
        $syncTone = match (true) {
            ! $latestSignIn => [
                'label' => 'Awaiting first sync',
                'copy' => 'Sign in to Politics & War to establish your first snapshot.',
                'pill' => 'nexus-user-status-pill-neutral',
            ],
            $syncAgeMinutes <= 60 => [
                'label' => 'Live sync window',
                'copy' => 'Your nation snapshot is fresh and ready for planning.',
                'pill' => 'nexus-user-status-pill-success',
            ],
            $syncAgeMinutes <= 360 => [
                'label' => 'Check sync cadence',
                'copy' => 'Data is still usable, but another sync would sharpen the picture.',
                'pill' => 'nexus-user-status-pill-warning',
            ],
            default => [
                'label' => 'Snapshot is stale',
                'copy' => 'Refresh your nation data before making large operational decisions.',
                'pill' => 'nexus-user-status-pill-error',
            ],
        };

        $readinessTone = match (true) {
            ($mmrScore ?? 0) >= 100 => [
                'label' => 'Doctrine locked',
                'copy' => 'Stockpile and force posture align with the current alliance target.',
                'pill' => 'nexus-user-status-pill-success',
            ],
            ($mmrScore ?? 0) >= 70 => [
                'label' => 'Close to target',
                'copy' => 'A few disciplined upgrades will move you fully into compliance.',
                'pill' => 'nexus-user-status-pill-warning',
            ],
            default => [
                'label' => 'Readiness gap',
                'copy' => 'Use the doctrine section below to decide the next build cycle.',
                'pill' => 'nexus-user-status-pill-error',
            ],
        };

        $resourceGaps = collect($mmrResourceBreakdown)
            ->filter(fn ($resource) => ! ($resource['met'] ?? false))
            ->sortByDesc(fn ($resource) => $resource['weight'] ?? 0)
            ->take(3)
            ->map(function ($resource, $key) {
                return [
                    'name' => str((string) $key)->headline()->toString(),
                    'have' => $resource['have'] ?? 0,
                    'required' => $resource['required'] ?? 0,
                    'weight' => $resource['weight'] ?? 0,
                ];
            })
            ->values();

        $unitLabels = [
            'soldiers' => 'Soldiers',
            'tanks' => 'Tanks',
            'aircraft' => 'Aircraft',
            'ships' => 'Ships',
            'missiles' => 'Missiles',
            'nukes' => 'Nukes',
            'spies' => 'Spies',
        ];

        $unitGaps = collect($unitLabels)
            ->map(function ($label, $unit) use ($latestSignIn, $nation, $mmrUnitRequirements) {
                $current = (int) ($latestSignIn?->$unit ?? $nation->$unit ?? 0);
                $required = (int) ($mmrUnitRequirements[$unit] ?? 0);

                return [
                    'label' => $label,
                    'current' => $current,
                    'required' => $required,
                    'gap' => max($required - $current, 0),
                ];
            })
            ->filter(fn ($unit) => $unit['gap'] > 0)
            ->sortByDesc('gap')
            ->take(3)
            ->values();

        $metrics = [
            [
                'label' => 'Direct deposit',
                'value' => '$' . number_format($afterTaxIncomeTotal ?? 0),
                'copy' => 'Last 30 days',
            ],
            [
                'label' => 'Tax contribution',
                'value' => '$' . number_format($taxTotal ?? 0),
                'copy' => 'Captured after deposits',
            ],
            [
                'label' => 'Score per city',
                'value' => number_format($scorePerCity ?? 0, 2),
                'copy' => 'Build efficiency pulse',
            ],
            [
                'label' => 'Cities online',
                'value' => number_format($nation->num_cities ?? 0),
                'copy' => 'Current operating tier',
            ],
        ];

        $commandDeck = [
            [
                'title' => 'Treasury lane',
                'copy' => 'Move money, confirm transfers, and keep your accounts from becoming a backlog.',
                'meta' => count($accountIds) . ' account' . (count($accountIds) === 1 ? '' : 's'),
                'route' => route('accounts'),
                'action' => 'Open treasury',
                'icon' => 'o-banknotes',
            ],
            [
                'title' => 'Growth lane',
                'copy' => 'Manage grants, loans, and the funding needed for your next city or project move.',
                'meta' => '$' . number_format(($grantTotal ?? 0) + ($loanTotal ?? 0), 0) . ' lifetime support',
                'route' => route('grants.city'),
                'action' => 'Review support',
                'icon' => 'o-gift',
            ],
            [
                'title' => 'Defense lane',
                'copy' => 'Jump into counters, simulators, intel, and the alliance tools that matter when pressure rises.',
                'meta' => ($mmrScore ?? 0) . '% readiness',
                'route' => route('defense.counters'),
                'action' => 'Open defense',
                'icon' => 'o-shield-check',
            ],
        ];

        $missionTiles = [
            ['label' => 'Alliance', 'value' => $allianceName],
            ['label' => 'Nation age', 'value' => number_format($nationAge ?? 0) . ' days'],
            ['label' => 'Score pulse', 'value' => $scorePulse],
            ['label' => 'Last sync', 'value' => $latestSignIn?->created_at?->diffForHumans() ?? 'Not captured'],
        ];

        $signalDesk = [
            [
                'title' => 'Sync cadence',
                'copy' => $syncTone['copy'],
                'meta' => $latestSignIn?->created_at?->diffForHumans() ?? 'No data',
                'pill' => $syncTone['pill'],
                'icon' => 'o-clock',
            ],
            [
                'title' => 'Doctrine watch',
                'copy' => $readinessTone['copy'],
                'meta' => ($mmrScore ?? 0) . '%',
                'pill' => $readinessTone['pill'],
                'icon' => 'o-rocket-launch',
            ],
            [
                'title' => 'Funding posture',
                'copy' => $payrollIsActive
                    ? 'Payroll is active and recent funding is landing in your ledger.'
                    : 'No active payroll track is attached; use support tools manually when needed.',
                'meta' => $payrollIsActive ? 'Active' : 'Manual',
                'pill' => $payrollIsActive ? 'nexus-user-status-pill-success' : 'nexus-user-status-pill-neutral',
                'icon' => 'o-credit-card',
            ],
        ];

        $chartTabs = [
            'score' => 'Score',
            'tax' => 'Tax flow',
            'resources' => 'Resource tax',
            'military' => 'Military',
            'holdings' => 'Holdings',
        ];
    @endphp

    <div class="nexus-user-shell" data-dashboard-theme>
        <section class="nexus-user-panel nexus-user-masthead">
            <div class="relative z-10 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
                <div class="flex flex-col gap-5 min-w-0">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-start gap-4">
                            <div class="avatar hidden sm:block">
                                <div class="w-20 rounded-[1.2rem] border border-base-300/70 bg-base-100/80 p-1">
                                    <img src="{{ $nation->flag ?? 'https://politicsandwar.com/img/flags/default.png' }}" alt="Nation flag" />
                                </div>
                            </div>

                            <div class="min-w-0 space-y-3">
                                <p class="nexus-user-eyebrow">{{ $greeting }} / member operations</p>
                                <div class="space-y-2">
                                    <h1 class="nexus-user-title">{{ $nation->leader_name }}</h1>
                                    <div class="min-w-0">
                                        <p class="text-lg font-semibold text-base-content break-words">{{ $nation->nation_name }}</p>
                                        <p class="text-sm text-base-content/60">A cleaner operating surface for alliance money, doctrine, and action routing.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('accounts') }}" class="btn btn-sm rounded-full border border-base-300 bg-base-100 text-base-content hover:bg-base-200">Treasury</a>
                            <a href="{{ route('defense.counters') }}" class="btn btn-sm rounded-full border border-base-300 bg-base-100 text-base-content hover:bg-base-200">Defense</a>
                            <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noreferrer" class="btn btn-sm rounded-full btn-ghost">P&amp;W profile</a>
                        </div>
                    </div>

                    <p class="nexus-user-body">
                        The goal here is simple: reduce the noise, keep the high-trust signals visible, and make the
                        next useful action obvious without burying you under a wall of decorative boxes.
                    </p>

                    <div class="nexus-user-kicker-list">
                        @foreach($missionTiles as $tile)
                            <span class="nexus-user-pill">{{ $tile['label'] }}: {{ $tile['value'] }}</span>
                        @endforeach
                    </div>
                </div>

                <aside class="nexus-user-side-rail rounded-[1rem] border border-base-300/70 p-4 sm:p-5">
                    <div class="nexus-user-section-head">
                        <div>
                            <p class="nexus-user-eyebrow">Status brief</p>
                            <h2 class="nexus-user-section-title">What needs attention</h2>
                        </div>
                        <span class="nexus-user-status-pill {{ $readinessTone['pill'] }}">
                            <span class="nexus-user-status-dot"></span>
                            {{ $readinessTone['label'] }}
                        </span>
                    </div>

                    <div class="mt-5 nexus-user-ledger">
                        <div class="nexus-user-ledger-item">
                            <div class="nexus-user-ledger-icon">
                                <x-icon name="o-clock" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-base-content">{{ $syncTone['label'] }}</p>
                                <p class="mt-1 nexus-user-microcopy">{{ $syncTone['copy'] }}</p>
                            </div>
                        </div>

                        <div class="nexus-user-ledger-item">
                            <div class="nexus-user-ledger-icon">
                                <x-icon name="o-shield-check" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-base-content">MMR posture at {{ $mmrScore ?? 0 }}%</p>
                                <p class="mt-1 nexus-user-microcopy">{{ $readinessTone['copy'] }}</p>
                            </div>
                        </div>

                        <div class="nexus-user-ledger-item">
                            <div class="nexus-user-ledger-icon">
                                <x-icon name="o-currency-dollar" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-base-content">
                                    {{ $payrollIsActive ? 'Payroll active' : 'Payroll inactive' }}
                                </p>
                                <p class="mt-1 nexus-user-microcopy">
                                    {{ $payrollIsActive && $payrollGrade
                                        ? $payrollGrade->name . ' at $' . number_format((float) $payrollDailyAmount, 2) . ' daily.'
                                        : 'No recurring payroll lane is attached to your nation right now.' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <section class="nexus-user-panel overflow-hidden">
            <div class="nexus-user-ribbon">
                @foreach($metrics as $metric)
                    <div class="nexus-user-ribbon-item">
                        <p class="nexus-user-stat-label">{{ $metric['label'] }}</p>
                        <p class="nexus-user-stat-value">{{ $metric['value'] }}</p>
                        <p class="nexus-user-stat-copy">{{ $metric['copy'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="nexus-user-panel overflow-hidden">
            <div class="grid xl:grid-cols-[minmax(0,1.12fr)_minmax(300px,0.88fr)]">
                <div class="p-5 sm:p-6">
                    <div class="nexus-user-section-head">
                        <div>
                            <p class="nexus-user-eyebrow">Command lanes</p>
                            <h2 class="nexus-user-section-title">Move to the right tool quickly</h2>
                            <p class="nexus-user-section-copy">The user side should route you into a workflow, not force you to visually parse twelve decorated cards first.</p>
                        </div>
                    </div>

                    <div class="mt-5 nexus-user-command-list">
                        @foreach($commandDeck as $command)
                            <div class="nexus-user-command-row">
                                <div class="nexus-user-ledger-icon">
                                    <x-icon name="{{ $command['icon'] }}" class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-base font-semibold text-base-content">{{ $command['title'] }}</p>
                                        <span class="nexus-user-status-pill nexus-user-status-pill-neutral">{{ $command['meta'] }}</span>
                                    </div>
                                    <p class="mt-2 text-sm leading-6 text-base-content/65 max-w-[54ch]">{{ $command['copy'] }}</p>
                                </div>
                                <div class="pt-1">
                                    <a href="{{ $command['route'] }}" class="btn btn-ghost btn-sm rounded-full whitespace-nowrap">{{ $command['action'] }}</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <aside class="nexus-user-side-rail border-t border-base-300/70 p-5 sm:p-6 xl:border-l xl:border-t-0">
                    <div class="nexus-user-section-head">
                        <div>
                            <p class="nexus-user-eyebrow">Doctrine brief</p>
                            <h2 class="nexus-user-section-title">Top gaps, not every detail</h2>
                        </div>
                    </div>

                    <div class="mt-5 space-y-5">
                        <div>
                            <div class="flex items-end justify-between gap-3">
                                <div>
                                    <p class="nexus-user-stat-label">Readiness</p>
                                    <p class="nexus-user-stat-value">{{ $mmrScore ?? 0 }}%</p>
                                </div>
                                <span class="nexus-user-status-pill {{ $readinessTone['pill'] }}">{{ $readinessTone['label'] }}</span>
                            </div>
                            <div class="mt-3 nexus-user-progress">
                                <span style="width: {{ min($mmrScore ?? 0, 100) }}%"></span>
                            </div>
                        </div>

                        <div class="space-y-3">
                            @forelse($resourceGaps as $gap)
                                <div class="nexus-user-data-tile">
                                    <p class="nexus-user-data-label">{{ $gap['name'] }}</p>
                                    <p class="nexus-user-data-value">{{ number_format($gap['have']) }} / {{ number_format($gap['required']) }}</p>
                                    <p class="mt-1 text-sm text-base-content/60">{{ number_format($gap['weight'], 2) }}% doctrine weight</p>
                                </div>
                            @empty
                                @forelse($unitGaps as $gap)
                                    <div class="nexus-user-data-tile">
                                        <p class="nexus-user-data-label">{{ $gap['label'] }}</p>
                                        <p class="nexus-user-data-value">+{{ number_format($gap['gap']) }}</p>
                                        <p class="mt-1 text-sm text-base-content/60">{{ number_format($gap['current']) }} current vs {{ number_format($gap['required']) }} required</p>
                                    </div>
                                @empty
                                    <div class="nexus-user-data-tile">
                                        <p class="nexus-user-data-label">Shortfalls</p>
                                        <p class="nexus-user-data-value">None</p>
                                        <p class="mt-1 text-sm text-base-content/60">Your current snapshot is aligned with alliance doctrine.</p>
                                    </div>
                                @endforelse
                            @endforelse
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <section class="nexus-user-panel overflow-hidden">
            <div class="grid xl:grid-cols-2">
                <div class="p-5 sm:p-6">
                    <x-user.resource-comparison :resources="$mmrResourceBreakdown" :weights="$mmrWeights" />
                </div>
                <div class="border-t border-base-300/70 p-5 sm:p-6 xl:border-l xl:border-t-0">
                    <x-user.military-comparison
                        :nation="$nation"
                        :latestSignIn="$latestSignIn"
                        :requirements="$mmrUnitRequirements"
                        :meets="$mmrUnitsMet"
                    />
                </div>
            </div>
        </section>

        <section x-data="{ activeTab: 'score' }" class="nexus-user-panel nexus-user-chart-card overflow-hidden">
            <div class="border-b border-base-300/70 px-5 pt-5 sm:px-6 sm:pt-6">
                <div class="nexus-user-section-head">
                    <div>
                        <p class="nexus-user-eyebrow">Telemetry</p>
                        <h2 class="nexus-user-section-title">Nation trendlines</h2>
                        <p class="nexus-user-section-copy">The visual layer stays quiet here so the actual movement in your data can carry the page.</p>
                    </div>
                </div>

                <div class="mt-4 flex gap-4 overflow-x-auto pb-px">
                    @foreach($chartTabs as $key => $label)
                        <button
                            @click="activeTab = '{{ $key }}'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                            :class="activeTab === '{{ $key }}' ? 'nexus-user-chart-tab-active' : 'border-transparent'"
                            class="nexus-user-chart-tab whitespace-nowrap"
                            type="button"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="p-5 sm:p-6">
                <div x-show="activeTab === 'score'" x-transition.opacity>
                    <canvas id="scoreChart" class="w-full" style="height: 320px"></canvas>
                </div>
                <div x-show="activeTab === 'tax'" x-cloak x-transition.opacity>
                    <canvas id="moneyTaxChart" class="w-full" style="height: 320px"></canvas>
                </div>
                <div x-show="activeTab === 'resources'" x-cloak x-transition.opacity>
                    <canvas id="resourceTaxChart" class="w-full" style="height: 320px"></canvas>
                </div>
                <div x-show="activeTab === 'military'" x-cloak x-transition.opacity>
                    <canvas id="militaryChart" class="w-full" style="height: 320px"></canvas>
                </div>
                <div x-show="activeTab === 'holdings'" x-cloak x-transition.opacity>
                    <canvas id="resourceHoldingsChart" class="w-full" style="height: 320px"></canvas>
                </div>
            </div>
        </section>

        <section class="nexus-user-panel overflow-hidden">
            <div class="grid xl:grid-cols-[minmax(0,1.15fr)_minmax(300px,0.85fr)]">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-300/70 px-5 py-4 sm:px-6">
                        <div>
                            <p class="nexus-user-eyebrow">Ledger</p>
                            <h2 class="nexus-user-section-title">Recent transactions</h2>
                        </div>
                        <a href="{{ route('accounts') }}" class="btn btn-ghost btn-sm rounded-full">Open accounts</a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table nexus-user-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Direction</th>
                                    <th>Amount</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($recentTransactions as $tx)
                                    @php
                                        $direction = in_array($tx->from_account_id, $accountIds, true) ? 'Sent' : 'Received';
                                        $isSent = $direction === 'Sent';
                                    @endphp
                                    <tr>
                                        <td class="text-sm text-base-content/70">{{ $tx->created_at->format('M d, Y H:i') }}</td>
                                        <td>
                                            <span class="nexus-user-status-pill {{ $isSent ? 'nexus-user-status-pill-neutral' : 'nexus-user-status-pill-success' }}">
                                                <span class="nexus-user-status-dot"></span>
                                                {{ $direction }}
                                            </span>
                                        </td>
                                        <td class="font-extrabold tabular-nums {{ $isSent ? 'text-base-content/75' : 'text-success' }}">
                                            {{ $isSent ? '-' : '+' }}${{ number_format($tx->money, 2) }}
                                        </td>
                                        <td class="max-w-sm truncate text-sm text-base-content/60">{{ $tx->note ?? 'No note' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <div class="nexus-user-empty">
                                                <x-icon name="o-inbox" class="size-10 text-base-content/25" />
                                                <div>
                                                    <p class="font-semibold text-base-content/70">No transactions recorded yet</p>
                                                    <p class="mt-1 text-sm">Treasury movement will appear here once you start using alliance accounts.</p>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <aside class="nexus-user-side-rail border-t border-base-300/70 p-5 sm:p-6 xl:border-l xl:border-t-0">
                    <div class="nexus-user-section-head">
                        <div>
                            <p class="nexus-user-eyebrow">Signal desk</p>
                            <h2 class="nexus-user-section-title">Operational notes</h2>
                        </div>
                        <a href="{{ route('user.settings') }}" class="btn btn-ghost btn-xs rounded-full">Settings</a>
                    </div>

                    <div class="mt-5 nexus-user-ledger">
                        @foreach($signalDesk as $signal)
                            <div class="nexus-user-ledger-item">
                                <div class="nexus-user-ledger-icon">
                                    <x-icon name="{{ $signal['icon'] }}" class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-base-content">{{ $signal['title'] }}</p>
                                        <span class="nexus-user-status-pill {{ $signal['pill'] }}">{{ $signal['meta'] }}</span>
                                    </div>
                                    <p class="mt-2 text-sm leading-6 text-base-content/65">{{ $signal['copy'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </aside>
            </div>
        </section>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof Chart === 'undefined') {
                    return;
                }

                const dashboardRoot = document.querySelector('[data-dashboard-theme]');

                if (! dashboardRoot) {
                    return;
                }

                const styles = getComputedStyle(dashboardRoot);
                const chartColors = {
                    primary: styles.getPropertyValue('--nexus-chart-primary').trim() || '#7b5f2a',
                    success: styles.getPropertyValue('--nexus-chart-success').trim() || '#2f6f4e',
                    info: styles.getPropertyValue('--nexus-chart-info').trim() || '#3b6f7b',
                    warning: styles.getPropertyValue('--nexus-chart-warning').trim() || '#b8741a',
                    danger: styles.getPropertyValue('--nexus-chart-danger').trim() || '#ab504a',
                    neutral: styles.getPropertyValue('--nexus-chart-neutral').trim() || '#686257',
                    text: styles.getPropertyValue('--nexus-chart-text').trim() || '#504a40',
                    grid: styles.getPropertyValue('--nexus-chart-grid').trim() || 'rgba(120, 107, 88, 0.16)',
                    tooltipBackground: styles.getPropertyValue('--nexus-chart-tooltip').trim() || 'rgba(42, 34, 28, 0.95)',
                    tooltipBorder: styles.getPropertyValue('--nexus-chart-tooltip-border').trim() || 'rgba(194, 174, 145, 0.18)',
                };

                const baseFont = {
                    family: "'Manrope', 'Segoe UI', sans-serif",
                    size: 11,
                    weight: 600,
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
                            backgroundColor: chartColors.tooltipBackground,
                            titleColor: '#f8fafc',
                            bodyColor: '#f5efe6',
                            borderColor: chartColors.tooltipBorder,
                            borderWidth: 1,
                            padding: 12,
                            cornerRadius: 12,
                            boxPadding: 4,
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: chartColors.text, font: baseFont },
                        },
                        y: {
                            grid: { color: chartColors.grid },
                            ticks: { color: chartColors.text, font: baseFont },
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
                            tension: 0.34,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            borderWidth: 2.2,
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
                            backgroundColor: rgba(chartColors.success, 0.74),
                            hoverBackgroundColor: rgba(chartColors.success, 0.88),
                            borderRadius: 8,
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
                    dataset.tension = 0.34;
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
                    dataset.tension = 0.32;
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

@extends('layouts.admin')

@section('title', 'Operations Overview')

@section('content')
    @php
        $formatNumber = static fn (mixed $value, int $decimals = 0): string => number_format(is_numeric($value) ? (float) $value : 0, $decimals);
        $formatMoney = static fn (mixed $value, int $decimals = 0): string => '$'.$formatNumber($value, $decimals);
        $can = static fn (string $permission): bool => auth()->user()?->can($permission) ?? false;

        $canViewMembers = $can('view-members');
        $canViewAccounts = $can('view-accounts');
        $canViewFinancialReports = $can('view-financial-reports');
        $canViewLoans = $can('view-loans');
        $canViewGrants = $can('view-grants');
        $canViewCityGrants = $can('view-city-grants');
        $canViewWars = $can('view-wars');
        $canViewMmr = $can('view-mmr');
        $canViewWarAid = $can('view-war-aid');
        $canViewRebuilding = $can('view-rebuilding');
        $canViewRaids = $can('view-raids');
        $canViewSpies = $can('view-spies');

        $canViewFinance = $canViewAccounts || $canViewFinancialReports;
        $canViewDefenseReadiness = $canViewWars || $canViewMmr;
        $hasDecisionQueue = $canViewMembers
            || $canViewLoans
            || $canViewGrants
            || $canViewCityGrants
            || $canViewWars
            || $canViewMmr
            || $canViewWarAid
            || $canViewRebuilding
            || $canViewRaids
            || $canViewSpies;
        $hasOperationalOverview = $hasDecisionQueue || $canViewFinance || $canViewDefenseReadiness;

        $powerGapCount = max(0, (int) $totalCities - (int) $poweredCities);
        $mmrReviewCount = max(0, (int) $totalMembers - (int) $mmrCompliantCount);
        $snapshotExpiresAt = $lastRefreshedAt->copy()->addMinutes($cacheTtlMinutes);
        $snapshotIsStale = $snapshotExpiresAt->isPast();
    @endphp

    <header class="nexus-page-header">
        <div class="nexus-page-header__copy">
            <h1 class="nexus-page-title">Operations overview</h1>
            <p class="nexus-page-summary">
                Current alliance workload and readiness signals, limited to the areas you are authorized to review.
                Snapshot updated
                <time datetime="{{ $lastRefreshedAt->toIso8601String() }}" title="{{ $lastRefreshedAt->format('M j, Y g:i A T') }}">
                    {{ $lastRefreshedAt->diffForHumans() }}
                </time>.
            </p>
        </div>

        <div class="nexus-page-header__actions">
            <span @class([
                'nexus-status',
                'nexus-status--warning' => $snapshotIsStale,
                'nexus-status--success' => ! $snapshotIsStale,
            ])>
                {{ $snapshotIsStale ? 'Refresh due' : $cacheTtlMinutes.' min cache' }}
            </span>
            <a href="{{ route('admin.dashboard', ['refresh' => 1]) }}" class="btn btn-outline btn-sm">
                <x-icon name="o-arrow-path" class="size-4" aria-hidden="true" />
                Refresh snapshot
            </a>
        </div>
    </header>

    @if ($hasDecisionQueue)
        <section class="nexus-panel nexus-panel--raised" aria-labelledby="decision-queue-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="decision-queue-title" class="nexus-section-title">Decision queue</h2>
                    <p class="nexus-body-muted mt-1">Authorized workspaces and readiness signals surfaced by the cached snapshot.</p>
                </div>
                <span class="nexus-status nexus-status--neutral">Permission scoped</span>
            </div>

            <div class="divide-y divide-base-300">
                @if ($canViewLoans)
                    <a href="{{ route('admin.loans') }}" class="group grid gap-3 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <span class="flex min-w-0 items-start gap-3">
                            <span class="nexus-icon-box bg-warning/10 text-warning">
                                <x-icon name="o-banknotes" class="size-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-base-content group-hover:text-primary">Loan approvals</span>
                                <span class="mt-0.5 block text-sm text-base-content/65">
                                    {{ $formatNumber($loanStats['active']) }} active or delinquent · {{ $formatMoney($loanStats['outstanding_balance']) }} outstanding
                                </span>
                            </span>
                        </span>
                        <span class="flex items-center justify-between gap-3 sm:justify-end">
                            <span @class([
                                'nexus-status',
                                'nexus-status--warning' => $loanStats['pending'] > 0,
                                'nexus-status--success' => $loanStats['pending'] === 0,
                            ])>
                                {{ $formatNumber($loanStats['pending']) }} pending
                            </span>
                            <x-icon name="o-chevron-right" class="size-4 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                @endif

                @if ($canViewGrants)
                    <a href="{{ route('admin.grants') }}" class="group grid gap-3 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <span class="flex min-w-0 items-start gap-3">
                            <span class="nexus-icon-box bg-info/10 text-info">
                                <x-icon name="o-gift" class="size-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-base-content group-hover:text-primary">Grant reviews</span>
                                <span class="mt-0.5 block text-sm text-base-content/65">
                                    {{ $formatNumber($grantStats['approved_this_week']) }} approved this week · {{ $formatMoney($grantStats['money_disbursed_30d']) }} issued in 30 days
                                </span>
                            </span>
                        </span>
                        <span class="flex items-center justify-between gap-3 sm:justify-end">
                            <span @class([
                                'nexus-status',
                                'nexus-status--warning' => $grantStats['pending'] > 0,
                                'nexus-status--success' => $grantStats['pending'] === 0,
                            ])>
                                {{ $formatNumber($grantStats['pending']) }} pending
                            </span>
                            <x-icon name="o-chevron-right" class="size-4 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                @endif

                @if ($canViewCityGrants)
                    <a href="{{ route('admin.grants.city') }}" class="group grid gap-3 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <span class="flex min-w-0 items-start gap-3">
                            <span class="nexus-icon-box bg-primary/10 text-primary">
                                <x-icon name="o-home-modern" class="size-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-base-content group-hover:text-primary">City grant queue</span>
                                <span class="mt-0.5 block text-sm text-base-content/65">Review city grant requests in the dedicated workspace.</span>
                            </span>
                        </span>
                        <span class="flex items-center justify-between gap-3 sm:justify-end">
                            <span class="nexus-status nexus-status--neutral">Open queue</span>
                            <x-icon name="o-chevron-right" class="size-4 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                @endif

                @if ($canViewWars)
                    <a href="{{ route('admin.war-room') }}" class="group grid gap-3 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <span class="flex min-w-0 items-start gap-3">
                            <span class="nexus-icon-box bg-error/10 text-error">
                                <x-icon name="o-bolt" class="size-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-base-content group-hover:text-primary">Active conflicts</span>
                                <span class="mt-0.5 block text-sm text-base-content/65">
                                    {{ $formatNumber($warsThisWeek) }} wars started in the last 7 days
                                    @if (! is_null($warTrend))
                                        · {{ $warTrend >= 0 ? '+' : '' }}{{ $formatNumber($warTrend, 1) }}% from the prior week
                                    @endif
                                </span>
                            </span>
                        </span>
                        <span class="flex items-center justify-between gap-3 sm:justify-end">
                            <span @class([
                                'nexus-status',
                                'nexus-status--warning' => $activeWars > 0,
                                'nexus-status--success' => $activeWars === 0,
                            ])>
                                {{ $formatNumber($activeWars) }} active
                            </span>
                            <x-icon name="o-chevron-right" class="size-4 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                @endif

                @if ($canViewMmr)
                    <a href="{{ route('admin.mmr.index') }}" class="group grid gap-3 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <span class="flex min-w-0 items-start gap-3">
                            <span class="nexus-icon-box bg-info/10 text-info">
                                <x-icon name="o-shield-check" class="size-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-base-content group-hover:text-primary">MMR readiness</span>
                                <span class="mt-0.5 block text-sm text-base-content/65">
                                    {{ $formatNumber($mmrCompliantCount) }} of {{ $formatNumber($totalMembers) }} nations meet the {{ $mmrThreshold }} threshold
                                </span>
                            </span>
                        </span>
                        <span class="flex items-center justify-between gap-3 sm:justify-end">
                            <span @class([
                                'nexus-status',
                                'nexus-status--warning' => $mmrReviewCount > 0,
                                'nexus-status--success' => $mmrReviewCount === 0,
                            ])>
                                {{ $formatNumber($mmrReviewCount) }} to review
                            </span>
                            <x-icon name="o-chevron-right" class="size-4 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                @endif

                @if ($canViewMembers)
                    <a href="{{ route('admin.cities.index') }}" class="group grid gap-3 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <span class="flex min-w-0 items-start gap-3">
                            <span class="nexus-icon-box bg-success/10 text-success">
                                <x-icon name="o-building-office-2" class="size-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-base-content group-hover:text-primary">City power coverage</span>
                                <span class="mt-0.5 block text-sm text-base-content/65">
                                    {{ $formatNumber($poweredCities) }} of {{ $formatNumber($totalCities) }} tracked cities are powered
                                </span>
                            </span>
                        </span>
                        <span class="flex items-center justify-between gap-3 sm:justify-end">
                            <span @class([
                                'nexus-status',
                                'nexus-status--warning' => $powerGapCount > 0,
                                'nexus-status--success' => $powerGapCount === 0,
                            ])>
                                {{ $formatNumber($powerGapCount) }} gaps
                            </span>
                            <x-icon name="o-chevron-right" class="size-4 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                @endif

                @if ($canViewWarAid)
                    <a href="{{ route('admin.war-aid') }}" class="group flex items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary">
                        <span><span class="font-semibold group-hover:text-primary">War aid</span><span class="ml-2 text-sm text-base-content/65">Review support requests.</span></span>
                        <x-icon name="o-chevron-right" class="size-4 shrink-0 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                    </a>
                @endif

                @if ($canViewRebuilding)
                    <a href="{{ route('admin.rebuilding.index') }}" class="group flex items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary">
                        <span><span class="font-semibold group-hover:text-primary">Rebuilding</span><span class="ml-2 text-sm text-base-content/65">Review rebuilding requests.</span></span>
                        <x-icon name="o-chevron-right" class="size-4 shrink-0 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                    </a>
                @endif

                @if ($canViewRaids)
                    <a href="{{ route('admin.raids.index') }}" class="group flex items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary">
                        <span><span class="font-semibold group-hover:text-primary">Raid operations</span><span class="ml-2 text-sm text-base-content/65">Open raid administration.</span></span>
                        <x-icon name="o-chevron-right" class="size-4 shrink-0 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                    </a>
                @endif

                @if ($canViewSpies)
                    <a href="{{ route('admin.spy-campaigns.index') }}" class="group flex items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-base-200/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary">
                        <span><span class="font-semibold group-hover:text-primary">Spy campaigns</span><span class="ml-2 text-sm text-base-content/65">Open campaign coordination.</span></span>
                        <x-icon name="o-chevron-right" class="size-4 shrink-0 text-base-content/40 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                    </a>
                @endif
            </div>
        </section>
    @endif

    @if ($canViewFinance)
        <section class="nexus-panel" aria-labelledby="finance-position-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="finance-position-title" class="nexus-section-title">Financial position</h2>
                    <p class="nexus-body-muted mt-1">Member holdings and revenue signals from the latest available records.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($canViewAccounts)
                        <a href="{{ route('admin.accounts.dashboard') }}" class="btn btn-ghost btn-sm">Accounts</a>
                    @endif
                    @if ($canViewFinancialReports)
                        <a href="{{ route('admin.finance.index') }}" class="btn btn-ghost btn-sm">Finance ledger</a>
                    @endif
                </div>
            </div>

            <div @class([
                'grid divide-y divide-base-300',
                'xl:grid-cols-[minmax(18rem,0.75fr)_minmax(0,1.25fr)] xl:divide-x xl:divide-y-0' => count($resourceValueBreakdown) > 0,
            ])>
                <dl class="divide-y divide-base-300">
                    <div class="px-5 py-4">
                        <dt class="nexus-stat-label">Reported member cash</dt>
                        <dd class="nexus-stat-value mt-1">{{ $formatMoney($cashTotal) }}</dd>
                        <p class="nexus-stat-helper mt-1">{{ $formatMoney($cashPerMember) }} per tracked member</p>
                    </div>

                    @if ($canViewFinancialReports)
                        <div class="px-5 py-4">
                            <dt class="nexus-stat-label">Tax income · 7 days</dt>
                            <dd class="nexus-stat-value mt-1">{{ $formatMoney($taxMoneyThisWeek) }}</dd>
                            <p class="nexus-stat-helper mt-1">
                                @if (is_null($taxMoneyTrend))
                                    No prior-week baseline
                                @else
                                    {{ $taxMoneyTrend >= 0 ? '+' : '' }}{{ $formatNumber($taxMoneyTrend, 1) }}% from the prior 7 days
                                @endif
                            </p>
                        </div>
                    @endif

                    <div class="px-5 py-4">
                        <dt class="nexus-stat-label">Estimated resource value</dt>
                        <dd class="nexus-stat-value mt-1">{{ $formatMoney($resourceTotalValue) }}</dd>
                        <p class="nexus-stat-helper mt-1">
                            {{ $latestTradePriceDate ? 'Market prices from '.$latestTradePriceDate : 'Market price snapshot unavailable' }}
                        </p>
                    </div>
                </dl>

                @if (count($resourceValueBreakdown) > 0)
                    <div class="min-w-0">
                        <div class="flex items-center justify-between gap-3 border-b border-base-300 px-5 py-3">
                            <h3 class="font-semibold">Resource exposure</h3>
                            <span class="text-xs text-base-content/60">Latest nation sign-ins</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="nexus-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Resource</th>
                                        <th scope="col" class="text-right">Units</th>
                                        <th scope="col" class="text-right">Est. value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($resourceValueBreakdown as $resource => $value)
                                        @php
                                            $resourceKey = is_string($resource) ? $resource : 'Other';
                                            $units = $resourceTotals[$resourceKey] ?? null;
                                        @endphp
                                        <tr>
                                            <td class="font-medium capitalize">{{ str_replace('_', ' ', $resourceKey) }}</td>
                                            <td class="text-right">{{ $units !== null ? $formatNumber($units, $units >= 1000 ? 0 : 2) : '—' }}</td>
                                            <td class="text-right font-medium">{{ $formatMoney($value) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if ($canViewMembers)
        <section class="nexus-panel" aria-labelledby="alliance-footprint-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="alliance-footprint-title" class="nexus-section-title">Alliance footprint</h2>
                    <p class="nexus-body-muted mt-1">A compact capacity view for member and city administration.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.members') }}" class="btn btn-ghost btn-sm">Members</a>
                    <a href="{{ route('admin.cities.index') }}" class="btn btn-ghost btn-sm">Cities</a>
                </div>
            </div>

            <dl class="nexus-metrics rounded-none border-0">
                <div class="nexus-metric">
                    <dt class="nexus-stat-label">Members</dt>
                    <dd class="nexus-stat-value">{{ $formatNumber($totalMembers) }}</dd>
                    <p class="nexus-stat-helper">Tracked alliance nations</p>
                </div>
                <div class="nexus-metric">
                    <dt class="nexus-stat-label">Cities</dt>
                    <dd class="nexus-stat-value">{{ $formatNumber($totalCities) }}</dd>
                    <p class="nexus-stat-helper">{{ $formatNumber($totalCities / max(1, $totalMembers), 1) }} per member</p>
                </div>
                <div class="nexus-metric">
                    <dt class="nexus-stat-label">Infrastructure</dt>
                    <dd class="nexus-stat-value">{{ $formatNumber($totalInfrastructure) }}</dd>
                    <p class="nexus-stat-helper">{{ $formatNumber($avgInfrastructure, 1) }} average per city</p>
                </div>
                <div class="nexus-metric">
                    <dt class="nexus-stat-label">Power coverage</dt>
                    <dd class="nexus-stat-value">{{ $formatNumber($powerCoverage, 1) }}%</dd>
                    <p class="nexus-stat-helper">{{ $formatNumber($powerGapCount) }} cities need review</p>
                </div>
            </dl>
        </section>
    @endif

    @if ($canViewDefenseReadiness)
        <section class="nexus-panel" aria-labelledby="defense-readiness-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="defense-readiness-title" class="nexus-section-title">Defense readiness</h2>
                    <p class="nexus-body-muted mt-1">Current conflicts and force capacity, without historical chart noise.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($canViewWars)
                        <a href="{{ route('admin.wars') }}" class="btn btn-ghost btn-sm">All wars</a>
                        <a href="{{ route('admin.war-room') }}" class="btn btn-primary btn-sm">War room</a>
                    @endif
                    @if ($canViewMmr)
                        <a href="{{ route('admin.mmr.index') }}" class="btn btn-ghost btn-sm">MMR</a>
                    @endif
                </div>
            </div>

            <div @class([
                'grid divide-y divide-base-300',
                'xl:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)] xl:divide-x xl:divide-y-0' => $canViewWars,
            ])>
                @if ($canViewWars)
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
                            <h3 class="font-semibold">Active wars</h3>
                            <span class="nexus-status {{ $activeWars > 0 ? 'nexus-status--warning' : 'nexus-status--success' }}">
                                {{ $formatNumber($activeWars) }} active
                            </span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="nexus-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Conflict</th>
                                        <th scope="col">State</th>
                                        <th scope="col" class="text-right">Resistance</th>
                                        <th scope="col" class="text-right">Impact</th>
                                        <th scope="col"><span class="sr-only">War timeline</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($activeWarDetails as $war)
                                        @php
                                            $attIsMember = in_array($war->att_id, $memberNationIds, true);
                                            $defIsMember = in_array($war->def_id, $memberNationIds, true);
                                            $infraDestroyed = $war->att_infra_destroyed + $war->def_infra_destroyed;
                                            $moneyLooted = $war->att_money_looted + $war->def_money_looted;
                                        @endphp
                                        <tr>
                                            <td>
                                                <a href="https://politicsandwar.com/nation/id={{ $war->att_id }}" target="_blank" rel="noopener" class="font-semibold text-primary hover:underline">
                                                    {{ $war->attacker?->leader_name ?? 'Unknown attacker' }}
                                                </a>
                                                @if ($attIsMember)
                                                    <span class="text-xs text-base-content/55">· alliance</span>
                                                @endif
                                                <span class="block text-xs text-base-content/55">
                                                    vs
                                                    <a href="https://politicsandwar.com/nation/id={{ $war->def_id }}" target="_blank" rel="noopener" class="hover:text-primary hover:underline">
                                                        {{ $war->defender?->leader_name ?? 'Unknown defender' }}
                                                    </a>
                                                    @if ($defIsMember)
                                                        · alliance
                                                    @endif
                                                </span>
                                            </td>
                                            <td>
                                                <span class="font-medium">{{ \Illuminate\Support\Str::headline($war->war_type) }}</span>
                                                <span class="block text-xs text-base-content/55">{{ $formatNumber($war->turns_left) }} turns left</span>
                                            </td>
                                            <td class="text-right tabular-nums">
                                                {{ $formatNumber($war->att_resistance) }} / {{ $formatNumber($war->def_resistance) }}
                                            </td>
                                            <td class="text-right">
                                                <span class="block font-medium">{{ $formatNumber($infraDestroyed) }} infra</span>
                                                <span class="block text-xs text-base-content/55">{{ $formatMoney($moneyLooted) }} loot</span>
                                            </td>
                                            <td class="text-right">
                                                <a href="https://politicsandwar.com/nation/war/timeline/war={{ $war->id }}" target="_blank" rel="noopener" class="link link-primary whitespace-nowrap text-sm font-semibold">
                                                    Timeline
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="py-8 text-center text-base-content/60">No active alliance wars in this snapshot.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <aside class="min-w-0 p-5" aria-label="Readiness summary">
                    @if ($canViewMmr)
                        <div class="border-b border-base-300 pb-5">
                            <div class="flex items-end justify-between gap-4">
                                <div>
                                    <p class="nexus-stat-label">MMR compliance</p>
                                    <p class="nexus-stat-value mt-1">{{ $formatNumber($mmrCoverage, 1) }}%</p>
                                </div>
                                <p class="text-right text-sm text-base-content/65">
                                    {{ $formatNumber($mmrCompliantCount) }} / {{ $formatNumber($totalMembers) }} compliant
                                </p>
                            </div>
                            <progress
                                class="progress {{ $mmrCoverage >= $mmrThreshold ? 'progress-success' : 'progress-warning' }} mt-3 h-2 w-full"
                                value="{{ min(100, max(0, $mmrCoverage)) }}"
                                max="100"
                                aria-label="MMR compliance {{ $formatNumber($mmrCoverage, 1) }} percent"
                            ></progress>
                        </div>
                    @endif

                    <div class="{{ $canViewMmr ? 'pt-5' : '' }}">
                        <h3 class="font-semibold">Force capacity</h3>
                        <p class="mt-1 text-sm text-base-content/60">Current totals against theoretical alliance capacity.</p>
                        <dl class="mt-4 divide-y divide-base-300">
                            @foreach ($militaryTotals as $unit => $total)
                                @php
                                    $capacity = $militaryCapacity[$unit] ?? 0;
                                    $readiness = $militaryReadiness[$unit] ?? 0;
                                @endphp
                                <div class="py-3 first:pt-0 last:pb-0">
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <dt class="font-medium capitalize">{{ str_replace('_', ' ', $unit) }}</dt>
                                        <dd class="tabular-nums text-base-content/65">
                                            {{ $formatNumber($total) }} / {{ $formatNumber($capacity) }}
                                        </dd>
                                    </div>
                                    <div class="mt-2 flex items-center gap-3">
                                        <progress class="progress progress-info h-1.5 flex-1" value="{{ min(100, max(0, $readiness)) }}" max="100" aria-label="{{ ucfirst($unit) }} readiness {{ $formatNumber($readiness, 1) }} percent"></progress>
                                        <span class="w-12 text-right text-xs tabular-nums text-base-content/60">{{ $formatNumber($readiness, 1) }}%</span>
                                    </div>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </aside>
            </div>
        </section>
    @endif

    @unless ($hasOperationalOverview)
        <section class="nexus-panel" aria-labelledby="restricted-overview-title">
            <div class="nexus-empty-state">
                <x-icon name="o-shield-check" class="size-8 text-base-content/40" aria-hidden="true" />
                <div>
                    <h2 id="restricted-overview-title" class="text-lg font-semibold">No operational summary available</h2>
                    <p class="mt-1 text-sm text-base-content/65">
                        This dashboard only exposes telemetry covered by your assigned permissions. Use the navigation to continue to an authorized workspace.
                    </p>
                </div>
            </div>
        </section>
    @endunless
@endsection

@extends('layouts.main')

@section('content')
    <x-chart-js />

    @php
        $greeting = match (true) {
            now()->hour < 12 => 'Good morning',
            now()->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
        $accountIds = $nation->accounts->pluck('id')->all();
        $hasReadinessSnapshot = $latestSignIn !== null;
        $readinessScore = min(100, max(0, (float) ($mmrScore ?? 0)));
        $readinessDisplay = number_format($readinessScore, floor($readinessScore) === $readinessScore ? 0 : 1);
        $readinessComplete = $hasReadinessSnapshot && $mmrResourcesMet && $mmrUnitsMet;
        $readinessStatus = match (true) {
            ! $hasReadinessSnapshot => 'Awaiting sync',
            $readinessComplete => 'Requirements met',
            default => 'Action needed',
        };
        $readinessStatusClass = match (true) {
            ! $hasReadinessSnapshot => 'nexus-status--neutral',
            $readinessComplete => 'nexus-status--success',
            default => 'nexus-status--warning',
        };
        $tierCityCount = $mmrTier?->city_count;
    @endphp

    <div class="nexus-stack">
        <header class="nexus-page-header sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <div class="flex min-w-0 items-start gap-4 sm:gap-5">
                <div class="aspect-[3/2] w-20 shrink-0 overflow-hidden rounded-md border border-base-300 bg-base-100 sm:w-24">
                    <img
                        src="{{ $nation->flag ?? 'https://politicsandwar.com/img/flags/default.png' }}"
                        alt="{{ $nation->nation_name }} nation flag"
                        class="h-full w-full object-cover"
                        loading="eager"
                        decoding="async"
                    >
                </div>

                <div class="nexus-page-header__copy">
                    <p class="nexus-kicker">{{ $greeting }}, {{ $nation->leader_name }}</p>
                    <h1 class="nexus-page-title break-words">{{ $nation->nation_name }}</h1>
                    <p class="nexus-page-summary">
                        {{ $nation->alliance->name ?? 'Unaffiliated' }}
                        <span aria-hidden="true">&middot;</span>
                        {{ number_format($nation->num_cities) }} {{ (int) $nation->num_cities === 1 ? 'city' : 'cities' }}
                        <span aria-hidden="true">&middot;</span>
                        {{ number_format($nation->score, 2) }} score
                    </p>
                    @if($latestSignIn && $nation->updated_at)
                        <p class="text-xs text-base-content/60">
                            Member record updated
                            <time datetime="{{ $nation->updated_at->toIso8601String() }}" title="{{ $nation->updated_at->format('M j, Y g:i A T') }}">
                                {{ $nation->updated_at->diffForHumans() }}
                            </time>
                        </p>
                    @endif
                </div>
            </div>

            <div class="nexus-page-header__actions sm:justify-end">
                <a href="{{ route('accounts') }}" class="btn btn-primary btn-sm flex-1 sm:flex-none">
                    <x-icon name="o-banknotes" class="size-4" aria-hidden="true" />
                    Open accounts
                </a>
                <a
                    href="https://politicsandwar.com/nation/id={{ $nation->id }}"
                    class="btn btn-ghost btn-sm flex-1 sm:flex-none"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    View on P&amp;W
                    <x-icon name="o-arrow-top-right-on-square" class="size-4" aria-hidden="true" />
                </a>
            </div>
        </header>

        <section
            class="grid overflow-hidden rounded-lg border border-base-300 bg-base-100 lg:grid-cols-[minmax(0,1.25fr)_minmax(20rem,0.75fr)]"
            aria-labelledby="readiness-heading"
        >
            <div class="flex flex-col gap-5 p-5 sm:p-6 lg:p-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="readiness-heading" class="nexus-section-title">Military readiness</h2>
                        <p class="nexus-body-muted mt-1">
                            @if($tierCityCount !== null)
                                Current requirements for the {{ number_format($tierCityCount) }}-city tier.
                            @else
                                No city-tier requirement is currently available.
                            @endif
                        </p>
                    </div>

                    <span class="nexus-status {{ $readinessStatusClass }}">
                        {{ $readinessStatus }}
                    </span>
                </div>

                @if($hasReadinessSnapshot)
                    <div>
                        <div class="flex items-baseline justify-between gap-4">
                            <p class="text-sm font-semibold text-base-content/75">Overall compliance</p>
                            <p class="font-bold tabular-nums">{{ $readinessDisplay }}%</p>
                        </div>
                        <progress
                            class="progress mt-2 h-2 w-full {{ $readinessComplete ? 'progress-success' : 'progress-warning' }}"
                            value="{{ $readinessScore }}"
                            max="100"
                            aria-label="Overall military readiness: {{ $readinessDisplay }} percent"
                        ></progress>
                    </div>

                    <p class="max-w-2xl text-sm leading-6 text-base-content/70">
                        @if($readinessComplete)
                            Your latest snapshot meets the current resource and unit requirements.
                        @else
                            Review the resource and unit comparisons to see which requirements still need attention.
                        @endif
                    </p>

                    <div>
                        <a href="#readiness-detail" class="btn btn-outline btn-sm">
                            {{ $readinessComplete ? 'Review readiness detail' : 'Review required actions' }}
                            <x-icon name="o-arrow-down" class="size-4" aria-hidden="true" />
                        </a>
                    </div>
                @else
                    <div class="nexus-empty-state min-h-0 place-items-start p-0 text-left">
                        <x-icon name="o-arrow-path" class="size-6 text-base-content/45" aria-hidden="true" />
                        <div>
                            <p class="font-semibold">No readiness snapshot yet</p>
                            <p class="mt-1 text-sm leading-6 text-base-content/65">
                                Readiness will appear after your nation data is synced for the first time.
                            </p>
                        </div>
                    </div>
                @endif
            </div>

            <dl class="grid gap-px border-t border-base-300 bg-base-300 sm:grid-cols-2 lg:grid-cols-1 lg:border-l lg:border-t-0">
                <div class="flex items-start justify-between gap-4 bg-base-100 p-5 sm:p-6">
                    <div>
                        <dt class="font-semibold">Resource stockpile</dt>
                        <dd class="mt-1 text-sm leading-6 text-base-content/65">
                            @if(! $hasReadinessSnapshot)
                                Waiting for current holdings.
                            @elseif($mmrResourcesMet)
                                Current holdings meet the tier target.
                            @else
                                One or more resources are below target.
                            @endif
                        </dd>
                    </div>
                    <span class="nexus-status {{ ! $hasReadinessSnapshot ? 'nexus-status--neutral' : ($mmrResourcesMet ? 'nexus-status--success' : 'nexus-status--warning') }}">
                        {{ ! $hasReadinessSnapshot ? 'Pending' : ($mmrResourcesMet ? 'Met' : 'Low') }}
                    </span>
                </div>

                <div class="flex items-start justify-between gap-4 bg-base-100 p-5 sm:p-6">
                    <div>
                        <dt class="font-semibold">Military units</dt>
                        <dd class="mt-1 text-sm leading-6 text-base-content/65">
                            @if(! $hasReadinessSnapshot)
                                Waiting for current unit counts.
                            @elseif($mmrUnitsMet)
                                Current forces meet the tier target.
                            @else
                                One or more unit counts are below target.
                            @endif
                        </dd>
                    </div>
                    <span class="nexus-status {{ ! $hasReadinessSnapshot ? 'nexus-status--neutral' : ($mmrUnitsMet ? 'nexus-status--success' : 'nexus-status--warning') }}">
                        {{ ! $hasReadinessSnapshot ? 'Pending' : ($mmrUnitsMet ? 'Ready' : 'Low') }}
                    </span>
                </div>
            </dl>
        </section>

        <section class="nexus-stack" aria-labelledby="financial-heading">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="financial-heading" class="nexus-section-title">Nation record</h2>
                    <p class="nexus-body-muted mt-1">Current nation details and activity recorded by the member system.</p>
                </div>

                <nav class="flex flex-wrap gap-x-5 gap-y-2 text-sm" aria-label="Financial actions">
                    <a href="{{ route('grants.city') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Request a grant</a>
                    <a href="{{ route('loans.index') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Manage loans</a>
                </nav>
            </div>

            <dl class="grid grid-cols-1 gap-px overflow-hidden rounded-lg border border-base-300 bg-base-300 sm:grid-cols-2 xl:grid-cols-3">
                <div class="bg-base-100 p-4 sm:p-5">
                    <dt class="nexus-stat-label">Direct deposit</dt>
                    <dd class="nexus-stat-value mt-2 break-words">${{ number_format($afterTaxIncomeTotal ?? 0) }}</dd>
                    <p class="nexus-stat-helper">Net deposits in the last 30 days</p>
                </div>
                <div class="bg-base-100 p-4 sm:p-5">
                    <dt class="nexus-stat-label">Tax recorded</dt>
                    <dd class="nexus-stat-value mt-2 break-words">${{ number_format($taxTotal ?? 0) }}</dd>
                    <p class="nexus-stat-helper">Cash tax in the last 30 days</p>
                </div>
                <div class="bg-base-100 p-4 sm:p-5">
                    <dt class="nexus-stat-label">Grants received</dt>
                    <dd class="nexus-stat-value mt-2 break-words">${{ number_format($grantTotal ?? 0) }}</dd>
                    <p class="nexus-stat-helper">Approved total recorded</p>
                </div>
                <div class="bg-base-100 p-4 sm:p-5">
                    <dt class="nexus-stat-label">Outstanding loans</dt>
                    <dd class="nexus-stat-value mt-2 break-words">${{ number_format($loanTotal ?? 0) }}</dd>
                    <p class="nexus-stat-helper">Current recorded balance</p>
                </div>
                <div class="bg-base-100 p-4 sm:p-5">
                    <dt class="nexus-stat-label">City count</dt>
                    <dd class="nexus-stat-value mt-2">{{ number_format($nation->num_cities ?? 0) }}</dd>
                    <p class="nexus-stat-helper">Cities currently built</p>
                </div>
                <div class="bg-base-100 p-4 sm:p-5">
                    <dt class="nexus-stat-label">Last nation sync</dt>
                    <dd class="mt-2 text-lg font-bold leading-tight">
                        @if($latestSignIn?->created_at)
                            <time datetime="{{ $latestSignIn->created_at->toIso8601String() }}" title="{{ $latestSignIn->created_at->format('M j, Y g:i A T') }}">
                                {{ $latestSignIn->created_at->diffForHumans() }}
                            </time>
                        @else
                            Not available
                        @endif
                    </dd>
                    <p class="nexus-stat-helper">Politics &amp; War data</p>
                </div>
            </dl>
        </section>

        <section class="nexus-panel" aria-labelledby="payroll-heading">
            <div class="grid gap-5 p-5 sm:p-6 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                <div class="flex min-w-0 items-start gap-4">
                    <div class="grid size-10 shrink-0 place-items-center rounded-md bg-secondary/12 text-secondary">
                        <x-icon name="o-currency-dollar" class="size-5" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 id="payroll-heading" class="nexus-section-title">Payroll</h2>
                            <span class="nexus-status {{ $payrollIsActive ? 'nexus-status--success' : 'nexus-status--neutral' }}">
                                {{ $payrollIsActive ? 'Active' : 'Inactive' }}
                            </span>
                        </div>

                        @if($payrollMember && $payrollGrade)
                            <p class="mt-2 font-semibold">{{ $payrollGrade->name }}</p>
                            <p class="mt-1 text-sm leading-6 text-base-content/65">
                                ${{ number_format((float) $payrollGrade->weekly_amount, 2) }} weekly
                                <span aria-hidden="true">&middot;</span>
                                ${{ number_format((float) $payrollDailyAmount, 2) }} daily
                            </p>
                        @else
                            <p class="mt-2 text-sm leading-6 text-base-content/65">You are not currently enrolled in payroll.</p>
                        @endif
                    </div>
                </div>

                <dl class="border-t border-base-300 pt-4 md:min-w-44 md:border-l md:border-t-0 md:pl-6 md:pt-0 md:text-right">
                    <dt class="nexus-stat-label">Paid in last 30 days</dt>
                    <dd class="mt-2 text-xl font-bold tabular-nums">${{ number_format($payrollMonthlyTotal ?? 0, 2) }}</dd>
                </dl>
            </div>
        </section>

        <section id="readiness-detail" class="scroll-mt-28 nexus-stack" aria-labelledby="readiness-detail-heading">
            <div>
                <h2 id="readiness-detail-heading" class="nexus-section-title">Readiness detail</h2>
                <p class="nexus-body-muted mt-1">Compare the latest synchronized holdings with your current tier requirements.</p>
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
        </section>

        <section class="nexus-panel overflow-hidden" aria-labelledby="transactions-heading">
            <header class="nexus-panel__header items-center">
                <div>
                    <h2 id="transactions-heading" class="nexus-section-title">Recent transactions</h2>
                    <p class="nexus-body-muted mt-1">The five latest transactions across your nation accounts.</p>
                </div>
                <a href="{{ route('accounts') }}" class="btn btn-ghost btn-sm">
                    View all accounts
                    <x-icon name="o-arrow-right" class="size-4" aria-hidden="true" />
                </a>
            </header>

            @if($recentTransactions->isEmpty())
                <div class="nexus-empty-state">
                    <x-icon name="o-inbox" class="size-7 text-base-content/40" aria-hidden="true" />
                    <div>
                        <p class="font-semibold">No account activity recorded</p>
                        <p class="mt-1 text-sm leading-6 text-base-content/65">
                            Deposits, transfers, and payroll transactions will appear here once they are posted.
                        </p>
                    </div>
                    <a href="{{ route('accounts') }}" class="btn btn-outline btn-sm">Open accounts</a>
                </div>
            @else
                <ul class="divide-y divide-base-300 sm:hidden" role="list">
                    @foreach($recentTransactions as $tx)
                        @php
                            $isSent = in_array($tx->from_account_id, $accountIds, true);
                            $direction = $isSent ? 'Sent' : 'Received';
                        @endphp
                        <li class="grid gap-3 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold">{{ $direction }}</p>
                                    <time class="mt-1 block text-xs text-base-content/60" datetime="{{ $tx->created_at->toIso8601String() }}">
                                        {{ $tx->created_at->format('M j, Y \a\t g:i A') }}
                                    </time>
                                </div>
                                <p class="font-bold tabular-nums {{ $isSent ? 'text-base-content/75' : 'text-success' }}">
                                    {{ $isSent ? '-' : '+' }}${{ number_format($tx->money, 2) }}
                                </p>
                            </div>
                            <p class="break-words text-sm leading-6 text-base-content/65">{{ $tx->note ?? 'No note provided' }}</p>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden overflow-x-auto sm:block">
                    <table class="table" data-sortable="false">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Direction</th>
                                <th scope="col" class="text-right">Amount</th>
                                <th scope="col">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentTransactions as $tx)
                                @php
                                    $isSent = in_array($tx->from_account_id, $accountIds, true);
                                    $direction = $isSent ? 'Sent' : 'Received';
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap">
                                        <time datetime="{{ $tx->created_at->toIso8601String() }}" title="{{ $tx->created_at->format('M j, Y g:i A T') }}">
                                            {{ $tx->created_at->format('M j, Y H:i') }}
                                        </time>
                                    </td>
                                    <td>
                                        <span class="nexus-direction-badge {{ $isSent ? 'nexus-status--neutral' : 'nexus-status--success' }}">
                                            <x-icon name="{{ $isSent ? 'o-arrow-up-right' : 'o-arrow-down-left' }}" class="size-3.5" aria-hidden="true" />
                                            {{ $direction }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap text-right font-bold tabular-nums {{ $isSent ? 'text-base-content/75' : 'text-success' }}">
                                        {{ $isSent ? '-' : '+' }}${{ number_format($tx->money, 2) }}
                                    </td>
                                    <td class="max-w-sm truncate" title="{{ $tx->note ?? 'No note provided' }}">{{ $tx->note ?? 'No note provided' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section
            id="nation-trends"
            class="nexus-panel scroll-mt-28 overflow-hidden"
            aria-labelledby="trends-heading"
            x-data="{ activeTab: 'score' }"
        >
            <header class="nexus-panel__header">
                <div>
                    <h2 id="trends-heading" class="nexus-section-title">Nation trends</h2>
                    <p class="nexus-body-muted mt-1">Up to 30 recent synchronized snapshots and 30 days of tax history.</p>
                </div>
            </header>

            <div
                class="flex gap-1 overflow-x-auto border-b border-base-300 px-3 pt-2 sm:px-5"
                role="tablist"
                aria-label="Nation trend charts"
            >
                <button
                    id="trend-tab-score"
                    type="button"
                    role="tab"
                    aria-controls="trend-panel-score"
                    :aria-selected="activeTab === 'score'"
                    :tabindex="activeTab === 'score' ? 0 : -1"
                    :class="activeTab === 'score' ? 'border-primary text-primary' : 'border-transparent text-base-content/65 hover:text-base-content'"
                    class="min-h-11 shrink-0 border-b-2 px-3 text-sm font-semibold transition-colors"
                    @click="activeTab = 'score'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                    @keydown.right.prevent="activeTab = 'tax'; $nextTick(() => document.getElementById('trend-tab-tax').focus())"
                    @keydown.left.prevent="activeTab = 'holdings'; $nextTick(() => document.getElementById('trend-tab-holdings').focus())"
                    @keydown.home.prevent="activeTab = 'score'; $nextTick(() => document.getElementById('trend-tab-score').focus())"
                    @keydown.end.prevent="activeTab = 'holdings'; $nextTick(() => document.getElementById('trend-tab-holdings').focus())"
                >Score</button>
                <button
                    id="trend-tab-tax"
                    type="button"
                    role="tab"
                    aria-controls="trend-panel-tax"
                    :aria-selected="activeTab === 'tax'"
                    :tabindex="activeTab === 'tax' ? 0 : -1"
                    :class="activeTab === 'tax' ? 'border-primary text-primary' : 'border-transparent text-base-content/65 hover:text-base-content'"
                    class="min-h-11 shrink-0 border-b-2 px-3 text-sm font-semibold transition-colors"
                    @click="activeTab = 'tax'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                    @keydown.right.prevent="activeTab = 'resources'; $nextTick(() => document.getElementById('trend-tab-resources').focus())"
                    @keydown.left.prevent="activeTab = 'score'; $nextTick(() => document.getElementById('trend-tab-score').focus())"
                    @keydown.home.prevent="activeTab = 'score'; $nextTick(() => document.getElementById('trend-tab-score').focus())"
                    @keydown.end.prevent="activeTab = 'holdings'; $nextTick(() => document.getElementById('trend-tab-holdings').focus())"
                >Tax revenue</button>
                <button
                    id="trend-tab-resources"
                    type="button"
                    role="tab"
                    aria-controls="trend-panel-resources"
                    :aria-selected="activeTab === 'resources'"
                    :tabindex="activeTab === 'resources' ? 0 : -1"
                    :class="activeTab === 'resources' ? 'border-primary text-primary' : 'border-transparent text-base-content/65 hover:text-base-content'"
                    class="min-h-11 shrink-0 border-b-2 px-3 text-sm font-semibold transition-colors"
                    @click="activeTab = 'resources'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                    @keydown.right.prevent="activeTab = 'military'; $nextTick(() => document.getElementById('trend-tab-military').focus())"
                    @keydown.left.prevent="activeTab = 'tax'; $nextTick(() => document.getElementById('trend-tab-tax').focus())"
                    @keydown.home.prevent="activeTab = 'score'; $nextTick(() => document.getElementById('trend-tab-score').focus())"
                    @keydown.end.prevent="activeTab = 'holdings'; $nextTick(() => document.getElementById('trend-tab-holdings').focus())"
                >Resource tax</button>
                <button
                    id="trend-tab-military"
                    type="button"
                    role="tab"
                    aria-controls="trend-panel-military"
                    :aria-selected="activeTab === 'military'"
                    :tabindex="activeTab === 'military' ? 0 : -1"
                    :class="activeTab === 'military' ? 'border-primary text-primary' : 'border-transparent text-base-content/65 hover:text-base-content'"
                    class="min-h-11 shrink-0 border-b-2 px-3 text-sm font-semibold transition-colors"
                    @click="activeTab = 'military'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                    @keydown.right.prevent="activeTab = 'holdings'; $nextTick(() => document.getElementById('trend-tab-holdings').focus())"
                    @keydown.left.prevent="activeTab = 'resources'; $nextTick(() => document.getElementById('trend-tab-resources').focus())"
                    @keydown.home.prevent="activeTab = 'score'; $nextTick(() => document.getElementById('trend-tab-score').focus())"
                    @keydown.end.prevent="activeTab = 'holdings'; $nextTick(() => document.getElementById('trend-tab-holdings').focus())"
                >Military</button>
                <button
                    id="trend-tab-holdings"
                    type="button"
                    role="tab"
                    aria-controls="trend-panel-holdings"
                    :aria-selected="activeTab === 'holdings'"
                    :tabindex="activeTab === 'holdings' ? 0 : -1"
                    :class="activeTab === 'holdings' ? 'border-primary text-primary' : 'border-transparent text-base-content/65 hover:text-base-content'"
                    class="min-h-11 shrink-0 border-b-2 px-3 text-sm font-semibold transition-colors"
                    @click="activeTab = 'holdings'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                    @keydown.right.prevent="activeTab = 'score'; $nextTick(() => document.getElementById('trend-tab-score').focus())"
                    @keydown.left.prevent="activeTab = 'military'; $nextTick(() => document.getElementById('trend-tab-military').focus())"
                    @keydown.home.prevent="activeTab = 'score'; $nextTick(() => document.getElementById('trend-tab-score').focus())"
                    @keydown.end.prevent="activeTab = 'holdings'; $nextTick(() => document.getElementById('trend-tab-holdings').focus())"
                >Holdings</button>
            </div>

            <p id="trend-unavailable" class="m-5 hidden rounded-md bg-warning/12 p-4 text-sm text-base-content" role="status">
                Trend charts could not load. The readiness, account, and transaction data above is still available.
            </p>

            <div class="p-4 sm:p-6">
                <div id="trend-panel-score" role="tabpanel" aria-labelledby="trend-tab-score" x-show="activeTab === 'score'">
                    @if(empty($scoreChart['labels']))
                        <div class="nexus-empty-state">
                            <p class="font-semibold">No score history available</p>
                            <p class="text-sm text-base-content/65">Score history will appear after synchronized snapshots are recorded.</p>
                        </div>
                    @else
                        <div class="relative h-72 sm:h-80">
                            <canvas id="scoreChart" role="img" aria-label="Nation score across recent synchronized snapshots">
                                Nation score trend chart.
                            </canvas>
                        </div>
                    @endif
                </div>
                <div id="trend-panel-tax" role="tabpanel" aria-labelledby="trend-tab-tax" x-show="activeTab === 'tax'" x-cloak>
                    @if(empty($moneyTaxChart['labels']))
                        <div class="nexus-empty-state">
                            <p class="font-semibold">No tax history available</p>
                            <p class="text-sm text-base-content/65">Tax revenue history will appear after tax records are posted.</p>
                        </div>
                    @else
                        <div class="relative h-72 sm:h-80">
                            <canvas id="moneyTaxChart" role="img" aria-label="Money taxed over the last 30 days">
                                Money tax trend chart.
                            </canvas>
                        </div>
                    @endif
                </div>
                <div id="trend-panel-resources" role="tabpanel" aria-labelledby="trend-tab-resources" x-show="activeTab === 'resources'" x-cloak>
                    @if(empty($resourceTaxChart['labels']))
                        <div class="nexus-empty-state">
                            <p class="font-semibold">No resource tax history available</p>
                            <p class="text-sm text-base-content/65">Resource tax history will appear after tax records are posted.</p>
                        </div>
                    @else
                        <div class="relative h-72 sm:h-80">
                            <canvas id="resourceTaxChart" role="img" aria-label="Resources taxed over the last 30 days">
                                Resource tax trend chart.
                            </canvas>
                        </div>
                    @endif
                </div>
                <div id="trend-panel-military" role="tabpanel" aria-labelledby="trend-tab-military" x-show="activeTab === 'military'" x-cloak>
                    @if(empty($militaryChart['labels']))
                        <div class="nexus-empty-state">
                            <p class="font-semibold">No military history available</p>
                            <p class="text-sm text-base-content/65">Military history will appear after synchronized snapshots are recorded.</p>
                        </div>
                    @else
                        <div class="relative h-72 sm:h-80">
                            <canvas id="militaryChart" role="img" aria-label="Military unit counts across recent synchronized snapshots">
                                Military unit trend chart.
                            </canvas>
                        </div>
                    @endif
                </div>
                <div id="trend-panel-holdings" role="tabpanel" aria-labelledby="trend-tab-holdings" x-show="activeTab === 'holdings'" x-cloak>
                    @if(empty($resourceHoldingsChart['labels']))
                        <div class="nexus-empty-state">
                            <p class="font-semibold">No holdings history available</p>
                            <p class="text-sm text-base-content/65">Resource holdings will appear after synchronized snapshots are recorded.</p>
                        </div>
                    @else
                        <div class="relative h-72 sm:h-80">
                            <canvas id="resourceHoldingsChart" role="img" aria-label="Resource holdings across recent synchronized snapshots">
                                Resource holdings trend chart.
                            </canvas>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    </div>

    @push('scripts')
        <x-chart-js />
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const unavailableMessage = document.getElementById('trend-unavailable');

                if (typeof Chart === 'undefined') {
                    unavailableMessage?.classList.remove('hidden');
                    return;
                }

                const charts = [];
                const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                const destroyCharts = () => {
                    while (charts.length > 0) {
                        charts.pop().destroy();
                    }
                };

                const renderCharts = () => {
                    destroyCharts();

                    const styles = getComputedStyle(document.documentElement);
                    const token = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;
                    const palette = {
                        primary: token('--color-primary', '#147d75'),
                        secondary: token('--color-secondary', '#9a741f'),
                        accent: token('--color-accent', '#b75f43'),
                        info: token('--color-info', '#287b96'),
                        warning: token('--color-warning', '#b37a1d'),
                        error: token('--color-error', '#b4483e'),
                        text: token('--color-base-content', '#172626'),
                        surface: token('--color-base-100', '#ffffff'),
                        grid: token('--nexus-rule', 'rgba(80, 95, 95, 0.18)'),
                    };
                    const seriesPalette = [
                        palette.primary,
                        palette.secondary,
                        palette.info,
                        palette.warning,
                        palette.accent,
                        palette.error,
                    ];
                    const baseFont = {
                        family: "'Commissioner Variable', ui-sans-serif, sans-serif",
                        size: 11,
                        weight: 500,
                    };
                    const commonOptions = {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: reduceMotion ? false : { duration: 180 },
                        interaction: { intersect: false, mode: 'index' },
                        plugins: {
                            legend: {
                                labels: {
                                    boxWidth: 9,
                                    boxHeight: 9,
                                    color: palette.text,
                                    font: baseFont,
                                    padding: 16,
                                    usePointStyle: true,
                                },
                            },
                            tooltip: {
                                backgroundColor: palette.text,
                                titleColor: palette.surface,
                                bodyColor: palette.surface,
                                borderColor: palette.grid,
                                borderWidth: 1,
                                padding: 10,
                                cornerRadius: 6,
                            },
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: palette.text, font: baseFont, maxRotation: 0 },
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: palette.grid },
                                ticks: { color: palette.text, font: baseFont },
                            },
                        },
                    };
                    const createChart = (id, configuration) => {
                        const canvas = document.getElementById(id);

                        if (canvas) {
                            charts.push(new Chart(canvas, configuration));
                        }
                    };

                    createChart('scoreChart', {
                        type: 'line',
                        data: {
                            labels: @json($scoreChart['labels']),
                            datasets: [{
                                label: 'Score',
                                data: @json($scoreChart['data']),
                                borderColor: palette.primary,
                                backgroundColor: palette.primary,
                                fill: false,
                                tension: 0.25,
                                pointRadius: 2,
                                pointHoverRadius: 4,
                                borderWidth: 2,
                            }],
                        },
                        options: {
                            ...commonOptions,
                            plugins: { ...commonOptions.plugins, legend: { display: false } },
                        },
                    });

                    createChart('moneyTaxChart', {
                        type: 'bar',
                        data: {
                            labels: @json($moneyTaxChart['labels']),
                            datasets: [{
                                label: 'Money',
                                data: @json($moneyTaxChart['data']),
                                backgroundColor: palette.primary,
                                borderRadius: 3,
                                borderSkipped: false,
                            }],
                        },
                        options: {
                            ...commonOptions,
                            plugins: { ...commonOptions.plugins, legend: { display: false } },
                        },
                    });

                    createChart('resourceTaxChart', {
                        type: 'bar',
                        data: {
                            labels: @json($resourceTaxChart['labels']),
                            datasets: [
                                @foreach($resourceTaxChart['resources'] as $rData)
                                    {
                                        label: @json($rData['label']),
                                        data: @json($rData['data']),
                                        backgroundColor: seriesPalette[{{ $loop->index }} % seriesPalette.length],
                                        borderRadius: 2,
                                        borderSkipped: false,
                                    },
                                @endforeach
                            ],
                        },
                        options: {
                            ...commonOptions,
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
                        dataset.tension = 0.25;
                        dataset.borderWidth = 2;
                        dataset.pointRadius = 1.5;
                        dataset.pointHoverRadius = 4;
                    });
                    createChart('militaryChart', {
                        type: 'line',
                        data: { labels: @json($militaryChart['labels']), datasets: militaryDatasets },
                        options: commonOptions,
                    });

                    const holdingsDatasets = @json($resourceHoldingsChart['datasets']);
                    holdingsDatasets.forEach((dataset, index) => {
                        dataset.borderColor = seriesPalette[index % seriesPalette.length];
                        dataset.backgroundColor = seriesPalette[index % seriesPalette.length];
                        dataset.fill = false;
                        dataset.tension = 0.25;
                        dataset.borderWidth = 2;
                        dataset.pointRadius = 1.5;
                        dataset.pointHoverRadius = 4;
                    });
                    createChart('resourceHoldingsChart', {
                        type: 'line',
                        data: { labels: @json($resourceHoldingsChart['labels']), datasets: holdingsDatasets },
                        options: commonOptions,
                    });
                };

                renderCharts();
                window.addEventListener('nexus:theme-changed', () => window.requestAnimationFrame(renderCharts));
            });
        </script>
    @endpush
@endsection

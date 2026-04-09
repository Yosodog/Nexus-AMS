@php
    use App\Services\PWHelperService;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $resourceList = PWHelperService::resources(false);
    $resourceListWithMoney = PWHelperService::resources(true);
    $resourceTotals = collect($resourceList)
        ->mapWithKeys(fn ($resource) => [$resource => $accounts->sum($resource)]);
    $resourceTotalsWithMoney = collect($resourceListWithMoney)
        ->mapWithKeys(fn ($resource) => [$resource => $accounts->sum($resource)]);
    $topAccounts = $accounts->sortByDesc('money')->take(5);
    $activeAccounts = $accounts->filter(fn ($account) => $account->user)->count();
    $inactiveAccounts = $accounts->count() - $activeAccounts;
    $averageTransactionsPerDay = $recentTransactionsSample
        ->groupBy(fn ($transaction) => $transaction->created_at->format('Y-m-d'))
        ->map->count()
        ->avg() ?? 0;

    $mainBankSnapshot ??= ['balances' => [], 'cached_at' => null];
    $offshoreSnapshots = ($offshoreSnapshots ?? collect()) instanceof \Illuminate\Support\Collection
        ? $offshoreSnapshots
        : collect($offshoreSnapshots);

    $mainBankTotals = collect($resourceListWithMoney)
        ->mapWithKeys(fn ($resource) => [$resource => (float) ($mainBankSnapshot['balances'][$resource] ?? 0)]);

    $offshoreTotals = collect($resourceListWithMoney)
        ->mapWithKeys(fn ($resource) => [
            $resource => $offshoreSnapshots->sum(
                fn ($snapshot) => (float) ($snapshot['balances'][$resource] ?? 0)
            ),
        ]);

    $allianceResourceTotals = $mainBankTotals->mapWithKeys(
        fn ($total, $resource) => [$resource => $total + ($offshoreTotals[$resource] ?? 0)]
    );

    $netResourcePositions = $allianceResourceTotals->mapWithKeys(
        fn ($total, $resource) => [$resource => $total - ($resourceTotalsWithMoney[$resource] ?? 0)]
    );

    $accountsCash = (float) ($resourceTotalsWithMoney['money'] ?? 0);
    $bankCash = (float) ($mainBankTotals['money'] ?? 0);
    $offshoreCash = (float) ($offshoreTotals['money'] ?? 0);
    $allianceCash = $bankCash + $offshoreCash;
    $netCashPosition = $allianceCash - $accountsCash;
    $coveragePercent = $accountsCash > 0 ? min(200, ($allianceCash / $accountsCash) * 100) : 100;

    $mainBankCachedDisplay = $mainBankSnapshot['cached_at']
        ? Carbon::parse($mainBankSnapshot['cached_at'])->diffForHumans()
        : 'Not cached';

    $offshoreCachedAt = $offshoreSnapshots
        ->map(fn ($snapshot) => $snapshot['cached_at'] ?? null)
        ->filter()
        ->map(fn ($cachedAt) => Carbon::parse($cachedAt))
        ->max();

    $offshoreCachedDisplay = $offshoreCachedAt ? $offshoreCachedAt->diffForHumans() : 'Not cached';
    $offshoreCount = $offshoreSnapshots->count();
    $resourcesWithLimitsCount = $withdrawalLimits->filter(fn ($limit) => (float) $limit->daily_limit > 0)->count();
    $coverageStatus = $coveragePercent >= 100 ? 'Fully covered' : 'Needs more liquidity';
    $coverageBadgeClass = $coveragePercent >= 100 ? 'badge-success' : 'badge-warning';
    $coverageProgressClass = $coveragePercent >= 100 ? 'progress-success' : 'progress-warning';
    $topAccountName = $topAccounts->isNotEmpty() ? $topAccounts->first()->name : 'No accounts yet';
    $topAccountBalance = $topAccounts->isNotEmpty() ? (float) $topAccounts->first()->money : 0;
    $surfaceCardClass = 'overflow-hidden rounded-3xl border border-base-300/60 bg-base-100 shadow-md';
    $statCardClass = 'rounded-2xl border border-base-300/60 bg-base-100 shadow-sm';

    $kpiCards = [
        [
            'title' => 'Tracked Accounts',
            'value' => number_format($accounts->count()),
            'icon' => 'o-users',
            'color' => 'text-primary',
            'description' => number_format($activeAccounts) . ' assigned · ' . number_format($inactiveAccounts) . ' unassigned',
        ],
        [
            'title' => 'Member Cash',
            'value' => '$' . number_format($accountsCash, 2),
            'icon' => 'o-currency-dollar',
            'color' => 'text-success',
            'description' => number_format($resourceTotals->count()) . ' tracked resources',
        ],
        [
            'title' => 'Average Balance',
            'value' => '$' . number_format($accounts->avg('money'), 2),
            'icon' => 'o-arrow-trending-up',
            'color' => 'text-warning',
            'description' => number_format($averageTransactionsPerDay, 1) . ' transactions/day',
        ],
        [
            'title' => 'Top Account',
            'value' => '$' . number_format($topAccountBalance, 2),
            'icon' => 'o-trophy',
            'color' => 'text-info',
            'description' => $topAccountName,
        ],
    ];

    $cashMixChart = [
        'labels' => ['Member Accounts', 'Main Bank', 'Offshores'],
        'data' => [
            round($accountsCash, 2),
            round($bankCash, 2),
            round($offshoreCash, 2),
        ],
    ];

    $topBalanceChart = [
        'labels' => $topAccounts
            ->map(fn ($account) => Str::limit($account->name, 18))
            ->values()
            ->all(),
        'data' => $topAccounts
            ->map(fn ($account) => round((float) $account->money, 2))
            ->values()
            ->all(),
    ];

    $resourceCushionChart = [
        'labels' => $netResourcePositions
            ->except('money')
            ->sortByDesc(fn ($total) => abs((float) $total))
            ->take(6)
            ->keys()
            ->map(fn ($resource) => Str::headline($resource))
            ->values()
            ->all(),
        'data' => $netResourcePositions
            ->except('money')
            ->sortByDesc(fn ($total) => abs((float) $total))
            ->take(6)
            ->map(fn ($total) => round((float) $total, 2))
            ->values()
            ->all(),
    ];
@endphp

@extends('layouts.admin')

@section('content')
    <x-header title="Account Management" separator>
        <x-slot:subtitle>Monitor alliance bank performance, approve withdrawals, and review direct deposits at a glance.</x-slot:subtitle>
        <x-slot:actions>
            <a href="#direct-deposit">
                <x-button label="Direct Deposit Hub" icon="o-building-library" class="btn-outline btn-sm" />
            </a>
            <x-button label="Refresh" icon="o-arrow-path" onclick="location.reload()" class="btn-ghost btn-sm" />
        </x-slot:actions>
    </x-header>

    <div class="mb-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-base-300/60 bg-base-100 px-4 py-3 shadow-sm">
            <div class="flex flex-wrap items-center gap-2 text-sm text-base-content/65">
                <span class="font-medium text-base-content">Alliance Liquidity</span>
                <span class="hidden text-base-content/30 sm:inline">•</span>
                <span>Main bank {{ $mainBankCachedDisplay }}</span>
                <span class="hidden text-base-content/30 sm:inline">•</span>
                <span>{{ $offshoreCount }} offshores · {{ $offshoreCachedDisplay }}</span>
                <span class="hidden text-base-content/30 sm:inline">•</span>
                <span>{{ $resourcesWithLimitsCount }} resource limits configured</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-badge :value="'Coverage ' . number_format($coveragePercent, 0) . '%'" :class="$coverageBadgeClass . ' badge-sm'" />
                <a href="#alliance-position" class="btn btn-primary btn-sm">Charts</a>
                <a href="#recent-transactions" class="btn btn-ghost btn-sm">Transactions</a>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-base-300/60 bg-base-100 p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest text-base-content/45">Member Accounts</p>
                        <p class="mt-2 text-2xl font-extrabold">${{ number_format($accountsCash, 2) }}</p>
                        <p class="mt-1 text-sm text-base-content/55">
                            {{ number_format($accounts->count()) }} tracked · Avg ${{ number_format($accounts->avg('money'), 2) }}
                        </p>
                    </div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                        <x-icon name="o-wallet" class="size-5 text-primary" />
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-base-300/60 bg-base-100 p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest text-base-content/45">Alliance Holdings</p>
                        <p class="mt-2 text-2xl font-extrabold">${{ number_format($allianceCash, 2) }}</p>
                        <p class="mt-1 text-sm text-base-content/55">
                            Bank ${{ number_format($bankCash, 2) }} · Offshores ${{ number_format($offshoreCash, 2) }}
                        </p>
                    </div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-info/10">
                        <x-icon name="o-building-library" class="size-5 text-info" />
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-base-300/60 bg-base-100 p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="text-xs font-semibold uppercase tracking-widest text-base-content/45">Liquidity Cushion</p>
                            <span class="badge {{ $coverageBadgeClass }} badge-sm">{{ $coverageStatus }}</span>
                        </div>
                        <p class="mt-2 text-2xl font-extrabold {{ $netCashPosition >= 0 ? 'text-success' : 'text-warning' }}">
                            {{ $netCashPosition >= 0 ? '+' : '' }}${{ number_format($netCashPosition, 2) }}
                        </p>
                        <p class="mt-1 text-sm text-base-content/55">
                            Alliance reserves cover {{ number_format($coveragePercent, 0) }}% of member cash balances.
                        </p>
                    </div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl {{ $netCashPosition >= 0 ? 'bg-success/10' : 'bg-warning/10' }}">
                        <x-icon name="o-arrow-trending-up" class="size-5 {{ $netCashPosition >= 0 ? 'text-success' : 'text-warning' }}" />
                    </div>
                </div>
                <x-progress :value="min(100, max(0, $coveragePercent))" class="mt-4 h-2 {{ $coverageProgressClass }}" />
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-6">
        @foreach($kpiCards as $card)
            <x-stat
                :title="$card['title']"
                :value="$card['value']"
                :description="$card['description']"
                :icon="$card['icon']"
                :color="$card['color']"
                :class="$statCardClass"
            />
        @endforeach
    </div>

    <div id="alliance-position" x-data="{ activeChart: 'liquidity' }" class="mb-6">
        <div class="{{ $surfaceCardClass }}">
            <div class="border-b border-base-200 px-5 pt-5 sm:px-6 sm:pt-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-bold">Alliance Position</h3>
                        <p class="text-sm text-base-content/50">Visualize cash mix, top balances, and resource cushion without leaving the dashboard.</p>
                    </div>
                    <x-badge :value="'Coverage ' . number_format($coveragePercent, 0) . '%'" :class="$coverageBadgeClass . ' badge-sm'" />
                </div>

                <div class="mt-4 flex gap-1 overflow-x-auto pb-px">
                    @foreach(['liquidity' => 'Liquidity Mix', 'leaders' => 'Top Balances', 'cushion' => 'Resource Cushion'] as $key => $label)
                        <button
                            @click="activeChart = '{{ $key }}'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                            :class="activeChart === '{{ $key }}'
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
                <div x-show="activeChart === 'liquidity'" x-transition.opacity>
                    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.8fr)]">
                        <div class="min-h-[320px]">
                            <canvas id="accountsLiquidityChart" class="w-full" style="height: 320px"></canvas>
                        </div>
                        <div class="space-y-3">
                            <div class="rounded-2xl border border-base-300/60 bg-base-200/30 p-4">
                                <p class="text-xs font-semibold uppercase tracking-widest text-base-content/45">Reserve mix</p>
                                <p class="mt-1 text-sm text-base-content/60">Separate bank and offshore balances so funding risk is easier to see at a glance.</p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                                <div class="rounded-2xl border border-base-300/60 bg-base-100 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-widest text-base-content/45">Members</p>
                                    <p class="mt-2 text-xl font-bold">${{ number_format($accountsCash, 2) }}</p>
                                </div>
                                <div class="rounded-2xl border border-base-300/60 bg-base-100 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-widest text-base-content/45">Main bank</p>
                                    <p class="mt-2 text-xl font-bold">${{ number_format($bankCash, 2) }}</p>
                                </div>
                                <div class="rounded-2xl border border-base-300/60 bg-base-100 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-widest text-base-content/45">Offshores</p>
                                    <p class="mt-2 text-xl font-bold">${{ number_format($offshoreCash, 2) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="activeChart === 'leaders'" x-cloak x-transition.opacity>
                    @if($topAccounts->isNotEmpty())
                        <div class="min-h-[320px]">
                            <canvas id="topBalancesChart" class="w-full" style="height: 320px"></canvas>
                        </div>
                    @else
                        <div class="flex min-h-[320px] items-center justify-center rounded-2xl border border-dashed border-base-300 text-sm text-base-content/50">
                            No account balance data is available yet.
                        </div>
                    @endif
                </div>

                <div x-show="activeChart === 'cushion'" x-cloak x-transition.opacity>
                    @if(!empty($resourceCushionChart['labels']))
                        <div class="min-h-[320px]">
                            <canvas id="resourceCushionChart" class="w-full" style="height: 320px"></canvas>
                        </div>
                    @else
                        <div class="flex min-h-[320px] items-center justify-center rounded-2xl border border-dashed border-base-300 text-sm text-base-content/50">
                            No resource position data is available yet.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Resource Ownership Table --}}
    <x-card class="{{ $surfaceCardClass }} mb-6">
        <x-slot:title>
            <div>
                Resource Ownership
                <div class="text-sm font-normal text-base-content/50">Comparing member-held balances to alliance bank + offshores (cached).</div>
            </div>
        </x-slot:title>
        <x-slot:menu>
            <span class="badge badge-ghost badge-sm">Bank {{ $mainBankCachedDisplay }} · Offshores {{ $offshoreCachedDisplay }}</span>
        </x-slot:menu>
        <div class="overflow-x-auto rounded-2xl border border-base-300/60">
            <table class="table table-sm table-zebra">
                <thead>
                    <tr class="text-xs uppercase tracking-wider text-base-content/45">
                        <th>Resource</th>
                        <th class="text-right">Member Accounts</th>
                        <th class="text-right">Alliance Holdings</th>
                        <th class="text-right">Cushion</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($resourceListWithMoney as $resource)
                        @php
                            $accountTotal = $resourceTotalsWithMoney[$resource] ?? 0;
                            $allianceTotal = $allianceResourceTotals[$resource] ?? 0;
                            $net = $netResourcePositions[$resource] ?? 0;
                            $isPositive = $net >= 0;
                        @endphp
                        <tr>
                            <td class="capitalize">{{ $resource }}</td>
                            <td class="text-right">{{ $resource === 'money' ? '$' : '' }}{{ number_format($accountTotal, 2) }}</td>
                            <td class="text-right">{{ $resource === 'money' ? '$' : '' }}{{ number_format($allianceTotal, 2) }}</td>
                            <td class="text-right {{ $isPositive ? 'text-success' : 'text-error' }}">
                                {{ $net >= 0 ? '+' : '' }}{{ $resource === 'money' ? '$' : '' }}{{ number_format($net, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- All Accounts Table --}}
    <x-card class="{{ $surfaceCardClass }} mb-6" x-data="{ search: '' }">
        <x-slot:title>
            <div>
                All Accounts
                <div class="text-sm font-normal text-base-content/50">Search, sort, and drill down into every managed bank account.</div>
            </div>
        </x-slot:title>
        <x-slot:menu>
            <x-badge value="{{ number_format($accounts->count()) }} accounts" class="badge-ghost badge-sm" />
            <x-input placeholder="Search accounts..." x-model="search" icon="o-magnifying-glass" class="input-sm w-56" clearable />
        </x-slot:menu>
        <div class="overflow-x-auto rounded-2xl border border-base-300/60">
            <table class="table table-sm table-zebra">
                <thead>
                    <tr class="text-xs uppercase tracking-wider text-base-content/45">
                        <th>Owner</th>
                        <th>Nation ID</th>
                        <th>Name</th>
                        <th class="text-right">Money</th>
                        @foreach($resourceList as $resource)
                            <th class="text-right">{{ ucfirst($resource) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $acc)
                        <tr :class="{ 'bg-error/10': {{ $acc->frozen ? 'true' : 'false' }} }"
                            x-show="!search || {{ \Illuminate\Support\Js::from(strtolower($acc->name . ' ' . ($acc->user?->name ?? ''))) }}.includes(search.toLowerCase())">
                            <td>
                                @if($acc->user)
                                    <a href="https://politicsandwar.com/nation/id={{ $acc->nation_id }}" target="_blank"
                                       class="link link-primary font-semibold">{{ $acc->user->name }}</a>
                                @else
                                    <span class="text-base-content/40 flex items-center gap-1">
                                        <x-icon name="o-user-minus" class="w-4 h-4" /> Deleted
                                    </span>
                                @endif
                            </td>
                            <td>{{ $acc->nation_id }}</td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('admin.accounts.view', $acc->id) }}" class="link link-primary font-semibold">
                                        {{ $acc->name }}
                                    </a>
                                    @if($acc->frozen)
                                        <x-badge  value="Frozen" class="badge-error badge-sm" />
                                    @endif
                                </div>
                            </td>
                            <td class="text-right">${{ number_format($acc->money, 2) }}</td>
                            @foreach($resourceList as $resource)
                                <td class="text-right">{{ number_format($acc->$resource, 2) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- Withdrawal Limits + Insights --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
        @can('manage-accounts')
            <div class="xl:col-span-2">
                <x-card class="{{ $surfaceCardClass }} h-full">
                    <x-slot:title>
                        <div>
                            Automatic Withdrawal Limits
                            <div class="text-sm font-normal text-base-content/50">Fine-tune automatic approvals across money and resource types.</div>
                        </div>
                    </x-slot:title>
                    <x-slot:menu>
                        <x-badge value="Controls" class="badge-info badge-sm" />
                    </x-slot:menu>
                    <form method="POST" action="{{ route('admin.withdrawals.limits') }}">
                        @csrf
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <x-input label="Max Automatic Withdrawals Per Day" type="number" min="0"
                                     name="max_daily_withdrawals"
                                     value="{{ old('max_daily_withdrawals', $maxDailyWithdrawals) }}"
                                     hint="Set to 0 for unlimited." required />

                            <div class="rounded-2xl border border-base-300/60 bg-base-200/40 p-4">
                                <div class="text-xs uppercase text-base-content/50 mb-3">At-a-glance</div>
                                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt class="text-base-content/60">Pending withdrawals</dt>
                                    <dd class="font-semibold">{{ number_format($pendingWithdrawals->count()) }}</dd>
                                    <dt class="text-base-content/60">Daily auto limit</dt>
                                    <dd class="font-semibold">{{ $maxDailyWithdrawals > 0 ? number_format($maxDailyWithdrawals) : 'Unlimited' }}</dd>
                                    <dt class="text-base-content/60">Resources with limits</dt>
                                    <dd class="font-semibold">{{ number_format($resourcesWithLimitsCount) }}</dd>
                                </dl>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-2xl border border-base-300/60 mb-4">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="text-xs uppercase tracking-wider text-base-content/45">
                                        <th>Resource</th>
                                        <th>Daily Auto-Approval Limit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach(PWHelperService::resources() as $resource)
                                        @php $limit = optional($withdrawalLimits->get($resource))->daily_limit ?? 0 @endphp
                                        <tr>
                                            <td class="capitalize">{{ $resource }}</td>
                                            <td>
                                                <label class="input input-sm flex items-center gap-1">
                                                    @if($resource === 'money')<span class="text-base-content/50">$</span>@endif
                                                    <input type="number" step="0.01" min="0"
                                                           name="limits[{{ $resource }}]"
                                                           value="{{ old('limits.' . $resource, $limit) }}"
                                                           class="grow">
                                                </label>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="flex justify-end">
                            <x-button label="Save Limits" type="submit" icon="o-check" class="btn-primary" />
                        </div>
                    </form>
                </x-card>
            </div>
        @endcan

        <x-card class="{{ $surfaceCardClass }} h-full">
            <x-slot:title>Insights</x-slot:title>
            <x-slot:subtitle>High-impact accounts and aggregate resource positions.</x-slot:subtitle>

            <div class="space-y-5">
                <div>
                    <div class="text-xs uppercase text-base-content/50 font-semibold mb-2">Top Balances</div>
                    <div class="divide-y divide-base-300">
                        @forelse($topAccounts as $account)
                            <div class="flex items-center justify-between py-2">
                                <div>
                                    <a href="{{ route('admin.accounts.view', $account->id) }}" class="link link-primary font-semibold text-sm">
                                        {{ $account->name }}
                                    </a>
                                    <div class="text-xs text-base-content/50">Nation #{{ $account->nation_id }}</div>
                                </div>
                                <span class="font-semibold text-sm">${{ number_format($account->money, 2) }}</span>
                            </div>
                        @empty
                            <p class="text-base-content/50 text-sm py-2">No accounts available.</p>
                        @endforelse
                    </div>
                </div>

                <div>
                    <div class="text-xs uppercase text-base-content/50 font-semibold mb-2">Resource Stockpile</div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        @foreach($resourceTotals as $resource => $total)
                            <dt class="text-base-content/60 capitalize">{{ $resource }}</dt>
                            <dd class="text-right font-semibold">{{ number_format($total, 2) }}</dd>
                        @endforeach
                    </dl>
                </div>

                <div>
                    <div class="text-xs uppercase text-base-content/50 font-semibold mb-2">Engagement</div>
                    <p class="text-sm text-base-content/60">
                        {{ number_format($activeAccounts) }} accounts assigned, {{ number_format($inactiveAccounts) }} unassigned.
                        Average of {{ number_format($averageTransactionsPerDay, 1) }} transactions/day.
                    </p>
                </div>
            </div>
        </x-card>
    </div>

    @can('manage-accounts')
        {{-- Withdrawal KPIs --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <x-stat title="Pending Withdrawals" :value="number_format($pendingWithdrawals->count())" icon="o-clock"
                    color="text-warning" description="Awaiting manual review" :class="$statCardClass" />
            <x-stat title="Daily Auto Limit" :value="$maxDailyWithdrawals > 0 ? number_format($maxDailyWithdrawals) : 'Unlimited'"
                    icon="o-arrow-path" color="text-info" description="Automatic approvals in 24 hours" :class="$statCardClass" />
            <x-stat title="Resources With Limits" :value="number_format($resourcesWithLimitsCount)"
                    icon="o-shield-check" color="text-success" description="Have an approval ceiling" :class="$statCardClass" />
        </div>

        @if($pendingWithdrawals->isNotEmpty())
            <x-card class="{{ $surfaceCardClass }} mb-6">
                <x-slot:title>
                    <div>
                        Pending Withdrawal Approvals
                        <div class="text-sm font-normal text-base-content/50">Review and action outstanding requests submitted by members.</div>
                    </div>
                </x-slot:title>
                <x-slot:menu>
                    <x-badge value="{{ number_format($pendingWithdrawals->count()) }} pending" class="badge-warning badge-sm" />
                </x-slot:menu>
                <div class="overflow-x-auto rounded-2xl border border-base-300/60">
                    <table class="table table-sm table-zebra">
                        <thead>
                            <tr class="text-xs uppercase tracking-wider text-base-content/45">
                                <th>Requested</th>
                                <th>Member</th>
                                <th>From Account</th>
                                <th>Nation</th>
                                <th>Resources</th>
                                <th>Reason</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pendingWithdrawals as $transaction)
                                <tr>
                                    <td>{{ $transaction->created_at?->format('M d, Y H:i') }}</td>
                                    <td>{{ $transaction->fromAccount?->user?->name ?? 'Unknown User' }}</td>
                                    <td>{{ $transaction->fromAccount?->name ?? 'Unknown Account' }}</td>
                                    <td>
                                        @if ($transaction->nation)
                                            <a href="https://politicsandwar.com/nation/id={{ $transaction->nation->id }}"
                                               target="_blank" class="link link-primary">
                                                {{ $transaction->nation->leader_name ?? ('Nation #'.$transaction->nation->id) }}
                                            </a>
                                            <div class="text-xs text-base-content/50">{{ $transaction->nation->nation_name ?? '' }}</div>
                                        @else
                                            <span class="text-base-content/50">Unknown</span>
                                        @endif
                                    </td>
                                    <td>
                                        <ul class="text-sm space-y-0.5">
                                            @foreach(PWHelperService::resources() as $resource)
                                                @php $amount = $transaction->{$resource} @endphp
                                                @if($amount > 0)
                                                    <li>{{ ucfirst($resource) }}: {{ $resource === 'money' ? '$' : '' }}{{ number_format($amount, 2) }}</li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td>{{ $transaction->pending_reason ?? 'Manual approval required' }}</td>
                                    <td class="text-right">
                                        <div class="flex flex-col items-end gap-2">
                                            <form action="{{ route('admin.withdrawals.approve', $transaction) }}" method="POST">
                                                @csrf
                                                <x-button label="Approve" icon="o-check-circle" type="submit" class="btn-success btn-sm" />
                                            </form>
                                            <div x-data="{ open: false }">
                                                <x-button label="Deny" icon="o-x-circle" @click="open = !open" class="btn-error btn-outline btn-sm" />
                                                <div x-show="open" x-cloak class="mt-2 p-3 bg-base-200 rounded-box w-64">
                                                    <form action="{{ route('admin.withdrawals.deny', $transaction) }}" method="POST">
                                                        @csrf
                                                        <x-textarea label="Reason" name="reason" rows="2" maxlength="500" required class="mb-2" />
                                                        <x-button label="Confirm Denial" type="submit" icon="o-x-circle" class="btn-error btn-sm w-full" />
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif
    @endcan

    {{-- Direct Deposit --}}
    <div id="direct-deposit" class="mb-6">
        @include('admin.accounts.direct_deposit')
    </div>

    {{-- Direct Deposit Logs --}}
    <x-card id="direct-deposit-logs" class="{{ $surfaceCardClass }} mb-6">
        <x-slot:title>
            <div class="flex items-center gap-2">
                <x-badge value="DD" class="badge-primary badge-sm" />
                Direct Deposit Logs
            </div>
            <div class="text-sm font-normal text-base-content/50">After-tax payouts with quick links to nations and deposit accounts.</div>
        </x-slot:title>
        <x-slot:menu>
            <a href="#mmr-assistant" class="link link-primary text-sm">Jump to MMR Assistant</a>
        </x-slot:menu>
        <div class="overflow-x-auto rounded-2xl border border-base-300/60">
            <table class="table table-sm table-zebra text-nowrap">
                <thead>
                    <tr class="text-xs uppercase tracking-wider text-base-content/45">
                        <th>Date</th>
                        <th>Nation</th>
                        <th>Deposit Account</th>
                        <th class="text-right">Cash Paid</th>
                        <th>Resources Delivered</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($directDepositLogs as $log)
                        @php
                            $deliveredResources = collect(PWHelperService::resources(false))
                                ->filter(fn ($res) => (float) $log->$res > 0)
                                ->mapWithKeys(fn ($res) => [$res => $log->$res]);
                        @endphp
                        <tr>
                            <td>{{ $log->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>
                                <a href="https://politicsandwar.com/nation/id={{ $log->nation_id }}" target="_blank" class="link link-primary">
                                    Nation #{{ $log->nation_id }}
                                </a>
                            </td>
                            <td>
                                @if($log->account)
                                    <a href="{{ route('admin.accounts.view', $log->account->id) }}" class="link link-primary font-semibold">
                                        {{ $log->account->name }}
                                    </a>
                                    @if($log->account->user)
                                        <div class="text-xs text-base-content/50">{{ $log->account->user->name }}</div>
                                    @endif
                                @else
                                    <span class="text-base-content/50">Account removed</span>
                                @endif
                            </td>
                            <td class="text-right">${{ number_format((float) $log->money, 2) }}</td>
                            <td>
                                @if($deliveredResources->isNotEmpty())
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($deliveredResources as $resource => $amount)
                                            <x-badge  value="{{ ucfirst($resource) }}: {{ number_format((float) $amount, 2) }}" class="badge-ghost badge-sm" />
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-base-content/50">Money only</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-base-content/50 py-6">No direct deposit activity recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2">
                <span class="text-sm text-base-content/50">Showing {{ $directDepositLogs->count() }} of {{ $directDepositLogs->total() }} entries</span>
                <div class="sm:ml-auto">{{ $directDepositLogs->withQueryString()->links() }}</div>
            </div>
        </x-slot:footer>
    </x-card>

    {{-- MMR Assistant --}}
    <x-card id="mmr-assistant" class="{{ $surfaceCardClass }} mb-6">
        <x-slot:title>
            <div class="flex items-center gap-2">
                <x-badge value="MMR" class="badge-neutral badge-sm" />
                MMR Assistant Purchases
            </div>
            <div class="text-sm font-normal text-base-content/50">Withheld cash reinvested into resources based on player configs.</div>
        </x-slot:title>
        <x-slot:menu>
            <a href="#direct-deposit-logs" class="link link-primary text-sm">Back to DD Logs</a>
        </x-slot:menu>
        <div class="overflow-x-auto rounded-2xl border border-base-300/60">
            <table class="table table-sm table-zebra text-nowrap">
                <thead>
                    <tr class="text-xs uppercase tracking-wider text-base-content/45">
                        <th>Date</th>
                        <th>Account</th>
                        <th>Nation</th>
                        <th class="text-right">Total Spent</th>
                        <th>Resources Purchased</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($mmrPurchases as $purchase)
                        @php
                            $purchasedResources = collect(PWHelperService::resources(false))
                                ->filter(fn ($res) => (float) $purchase->$res > 0)
                                ->mapWithKeys(fn ($res) => [$res => [
                                    'qty' => $purchase->$res,
                                    'ppu' => $purchase->getAttribute("{$res}_ppu"),
                                ]]);
                        @endphp
                        <tr>
                            <td>{{ $purchase->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>
                                @if($purchase->account)
                                    <a href="{{ route('admin.accounts.view', $purchase->account->id) }}" class="link link-primary font-semibold">
                                        {{ $purchase->account->name }}
                                    </a>
                                @else
                                    <span class="text-base-content/50">Account removed</span>
                                @endif
                            </td>
                            <td>
                                @if($purchase->account?->nation_id)
                                    <a href="https://politicsandwar.com/nation/id={{ $purchase->account->nation_id }}" target="_blank" class="link link-neutral">
                                        Nation #{{ $purchase->account->nation_id }}
                                    </a>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td class="text-right">${{ number_format((float) $purchase->total_spent, 2) }}</td>
                            <td>
                                @if($purchasedResources->isNotEmpty())
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($purchasedResources as $resource => $data)
                                            <x-badge class="badge-ghost badge-sm">
                                                {{ ucfirst($resource) }}: {{ number_format((float) $data['qty'], 2) }}
                                                @if($data['ppu'])
                                                    <span class="text-base-content/50"> @ ${{ number_format((float) $data['ppu'], 2) }}</span>
                                                @endif
                                            </x-badge>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-base-content/50">No resources purchased</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-base-content/50 py-6">No MMR Assistant purchases yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2">
                <span class="text-sm text-base-content/50">Showing {{ $mmrPurchases->count() }} of {{ $mmrPurchases->total() }} purchases</span>
                <div class="sm:ml-auto">{{ $mmrPurchases->withQueryString()->links() }}</div>
            </div>
        </x-slot:footer>
    </x-card>

    {{-- Recent Transactions --}}
    <x-card id="recent-transactions" class="{{ $surfaceCardClass }} mb-6">
        <x-slot:title>
            Recent Transactions
            <div class="text-sm font-normal text-base-content/50">Paginated, newest-first view of alliance banking activity.</div>
        </x-slot:title>
        <div class="overflow-x-auto rounded-2xl border border-base-300/60">
            <table class="table table-sm table-zebra text-nowrap">
                <thead>
                    <tr class="text-xs uppercase tracking-wider text-base-content/45">
                        <th>Date</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Type</th>
                        <th class="text-right">Money</th>
                        @foreach(PWHelperService::resources(false) as $resource)
                            <th class="text-right">{{ ucfirst($resource) }}</th>
                        @endforeach
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentTransactions as $transaction)
                        <tr>
                            <td>{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                            <td>
                                @if($transaction->fromAccount)
                                    <a href="{{ route('admin.accounts.view', $transaction->fromAccount->id) }}" class="link link-primary">
                                        {{ $transaction->fromAccount->name }}
                                    </a>
                                @elseif($transaction->nation_id && $transaction->transaction_type === 'deposit')
                                    <a href="https://politicsandwar.com/nation/id={{ $transaction->nation_id }}" target="_blank" class="link link-primary">
                                        Nation #{{ $transaction->nation_id }}
                                    </a>
                                @else
                                    <span class="text-base-content/50">N/A</span>
                                @endif
                            </td>
                            <td>
                                @if($transaction->toAccount)
                                    <a href="{{ route('admin.accounts.view', $transaction->toAccount->id) }}" class="link link-primary">
                                        {{ $transaction->toAccount->name }}
                                    </a>
                                @elseif($transaction->nation_id)
                                    <a href="https://politicsandwar.com/nation/id={{ $transaction->nation_id }}" target="_blank" class="link link-primary">
                                        Nation #{{ $transaction->nation_id }}
                                    </a>
                                @else
                                    <span class="text-base-content/50">N/A</span>
                                @endif
                            </td>
                            <td>{{ ucfirst($transaction->transaction_type) }}</td>
                            <td class="text-right">${{ number_format($transaction->money, 2) }}</td>
                            @foreach(PWHelperService::resources(false) as $resource)
                                <td class="text-right">{{ number_format($transaction->$resource, 2) }}</td>
                            @endforeach
                            <td class="text-right">
                                @if($transaction->isNationWithdrawal() && !$transaction->isRefunded() && Gate::allows('manage-accounts'))
                                    <form method="POST"
                                          action="{{ route('admin.accounts.transactions.refund', $transaction) }}"
                                          onsubmit="return confirm('Are you sure you want to refund this transaction?');">
                                        @csrf
                                        <x-button label="Refund" icon="o-arrow-uturn-left" type="submit" class="btn-error btn-outline btn-xs" />
                                    </form>
                                @elseif($transaction->isRefunded())
                                    <x-badge  value="Refunded" class="badge-ghost badge-sm" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 6 + count(PWHelperService::resources(false)) }}" class="text-center text-base-content/50 py-6">
                                No transactions found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2">
                <span class="text-sm text-base-content/50">Showing {{ $recentTransactions->count() }} of {{ $recentTransactions->total() }} transactions</span>
                <div class="sm:ml-auto">{{ $recentTransactions->links() }}</div>
            </div>
        </x-slot:footer>
    </x-card>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof Chart === 'undefined') {
                return;
            }

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
                text: '#475569',
                grid: 'rgba(148, 163, 184, 0.16)',
                tooltipBackground: 'rgba(15, 23, 42, 0.92)',
                tooltipBorder: 'rgba(148, 163, 184, 0.22)',
            };

            const tickColor = chartColors.text;
            const gridColor = chartColors.grid;
            const tooltipBackground = chartColors.tooltipBackground;
            const tooltipBorder = chartColors.tooltipBorder;
            const legendColor = chartColors.text;

            const moneyFormat = (value) => '$' + new Intl.NumberFormat('en-US', {
                maximumFractionDigits: 2,
            }).format(value ?? 0);

            const baseFont = {
                family: "'Inter', 'system-ui', sans-serif",
                size: 11,
                weight: 500,
            };

            const commonOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: legendColor,
                            font: baseFont,
                            boxWidth: 12,
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
                    },
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: tickColor, font: baseFont },
                    },
                    y: {
                        grid: { color: gridColor },
                        ticks: { color: tickColor, font: baseFont },
                        beginAtZero: true,
                    },
                },
            };

            const liquidityCanvas = document.getElementById('accountsLiquidityChart');
            if (liquidityCanvas) {
                new Chart(liquidityCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: @json($cashMixChart['labels']),
                        datasets: [{
                            data: @json($cashMixChart['data']),
                            backgroundColor: [
                                rgba(chartColors.primary, 0.88),
                                rgba(chartColors.success, 0.88),
                                rgba(chartColors.info, 0.88),
                            ],
                            borderColor: '#ffffff',
                            borderWidth: 3,
                            hoverOffset: 10,
                        }],
                    },
                    options: {
                        ...commonOptions,
                        cutout: '62%',
                        scales: {},
                        plugins: {
                            ...commonOptions.plugins,
                            tooltip: {
                                ...commonOptions.plugins.tooltip,
                                callbacks: {
                                    label(context) {
                                        return `${context.label}: ${moneyFormat(context.parsed)}`;
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const topBalancesCanvas = document.getElementById('topBalancesChart');
            if (topBalancesCanvas) {
                new Chart(topBalancesCanvas, {
                    type: 'bar',
                    data: {
                        labels: @json($topBalanceChart['labels']),
                        datasets: [{
                            label: 'Balance',
                            data: @json($topBalanceChart['data']),
                            backgroundColor: rgba(chartColors.primary, 0.72),
                            hoverBackgroundColor: rgba(chartColors.primary, 0.9),
                            borderRadius: 10,
                            borderSkipped: false,
                        }],
                    },
                    options: {
                        ...commonOptions,
                        plugins: {
                            ...commonOptions.plugins,
                            legend: { display: false },
                            tooltip: {
                                ...commonOptions.plugins.tooltip,
                                callbacks: {
                                    label(context) {
                                        return `Balance: ${moneyFormat(context.parsed.y)}`;
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                ...commonOptions.scales.x,
                                grid: { display: false },
                            },
                            y: {
                                ...commonOptions.scales.y,
                                ticks: {
                                    ...commonOptions.scales.y.ticks,
                                    callback(value) {
                                        return moneyFormat(value);
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const cushionCanvas = document.getElementById('resourceCushionChart');
            if (cushionCanvas) {
                new Chart(cushionCanvas, {
                    type: 'bar',
                    data: {
                        labels: @json($resourceCushionChart['labels']),
                        datasets: [{
                            label: 'Net position',
                            data: @json($resourceCushionChart['data']),
                            backgroundColor(context) {
                                return context.raw >= 0
                                    ? rgba(chartColors.success, 0.76)
                                    : rgba(chartColors.warning, 0.82);
                            },
                            hoverBackgroundColor(context) {
                                return context.raw >= 0
                                    ? rgba(chartColors.success, 0.92)
                                    : rgba(chartColors.warning, 0.95);
                            },
                            borderRadius: 10,
                            borderSkipped: false,
                        }],
                    },
                    options: {
                        ...commonOptions,
                        indexAxis: 'y',
                        plugins: {
                            ...commonOptions.plugins,
                            legend: { display: false },
                            tooltip: {
                                ...commonOptions.plugins.tooltip,
                                callbacks: {
                                    label(context) {
                                        const value = new Intl.NumberFormat('en-US', {
                                            maximumFractionDigits: 2,
                                        }).format(context.parsed.x);

                                        return `Net: ${value}`;
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                ...commonOptions.scales.x,
                                ticks: {
                                    ...commonOptions.scales.x.ticks,
                                    callback(value) {
                                        return new Intl.NumberFormat('en-US', {
                                            maximumFractionDigits: 0,
                                        }).format(value);
                                    },
                                },
                            },
                            y: {
                                ...commonOptions.scales.y,
                                grid: { display: false },
                            },
                        },
                    },
                });
            }
        });
    </script>
@endpush

@extends('layouts.admin')

@section('content')
    @php
        $formatNumber = static fn (mixed $value, int $decimals = 0): string => number_format(is_numeric($value) ? (float) $value : 0, $decimals);
        $formatMoney = static fn (mixed $value, int $decimals = 0): string => '$' . $formatNumber($value, $decimals);

        // Map Bootstrap Icon names → Heroicon names
        $iconMap = [
            'o-users'         => 'o-users',
            'o-arrow-trending-up'      => 'o-arrow-trending-up',
            'o-shield-check-lock'         => 'o-shield-check',
            'o-bolt'    => 'o-bolt',
            'o-banknotes'          => 'o-currency-dollar',
            'o-exclamation-triangle' => 'o-exclamation-circle',
        ];
        // Map Bootstrap bg classes → DaisyUI color classes
        $colorMap = [
            'badge-primary' => 'text-primary',
            'badge-success' => 'text-success',
            'badge-info'    => 'text-info',
            'badge-warning' => 'text-warning',
            'text-bg-dark'    => 'text-neutral-content',
            'badge-error'  => 'text-error',
        ];
    @endphp

    {{-- Page Header --}}
    <x-header title="Alliance Dashboard" separator>
        <x-slot:subtitle>
            Telemetry cached {{ $lastRefreshedAt->diffForHumans() }}
            (expires {{ $lastRefreshedAt->copy()->addMinutes($cacheTtlMinutes)->diffForHumans() }}).
        </x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap gap-2 items-center">
                <x-badge  value="{{ $formatNumber($totalMembers) }} Members" icon="o-users" class="badge-primary badge-lg" />
                <x-badge  value="{{ $formatNumber($totalCities) }} Cities" icon="o-building-office-2" class="badge-success badge-lg" />
                <x-badge  value="{{ $formatMoney($cashTotal, 0) }} Cash" icon="o-currency-dollar" class="badge-neutral badge-lg" />
                <a href="{{ route('admin.dashboard', ['refresh' => 1]) }}" class="btn btn-sm btn-outline btn-primary">
                    <x-icon name="o-arrow-path" class="size-4" />
                    Refresh
                </a>
            </div>
        </x-slot:actions>
    </x-header>

    {{-- KPI Stats --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
        @foreach ($kpis as $kpi)
            <x-stat
                :title="$kpi['title']"
                :value="$kpi['value']"
                :description="! empty($kpi['helper']) ? $kpi['helper'] : null"
                :icon="$iconMap[$kpi['icon']] ?? 'o-chart-bar'"
                :color="$colorMap[$kpi['bg']] ?? 'text-primary'"
            >
                @if (! is_null($kpi['trend']))
                    <x-slot:figure>
                        <x-badge
                            :value="($kpi['trend'] >= 0 ? '+' : '') . $formatNumber($kpi['trend'], 1) . '%'"
                            :class="$kpi['trend'] >= 0 ? 'badge-success badge-sm' : 'badge-error badge-sm'"
                        />
                    </x-slot:figure>
                @endif
            </x-stat>
        @endforeach
    </div>

    {{-- Tax Charts --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
        <x-card title="Tax Intake (Money)">
            <x-slot:menu>
                <x-badge :value="$formatMoney($taxMoneyThisWeek, 0) . ' / 7d'" class="badge-success badge-sm" />
                @if (! is_null($taxMoneyTrend))
                    <x-badge
                        :value="($taxMoneyTrend >= 0 ? '+' : '') . $formatNumber($taxMoneyTrend, 1) . '% vs prior week'"
                        :class="$taxMoneyTrend >= 0 ? 'badge-success badge-sm' : 'badge-error badge-sm'"
                    />
                @endif
            </x-slot:menu>
            <canvas id="taxMoneyChart" height="220"></canvas>
            <p class="text-base-content/50 text-sm mt-3">
                Shows cash delivered into alliance banks (primary + offshore) across the past two weeks.
            </p>
        </x-card>

        <x-card title="Tax Intake (Resources)">
            <canvas id="taxResourceChart" height="220"></canvas>
            <p class="text-base-content/50 text-sm mt-3">
                Focused on steel, munitions, aluminum, and food flows captured in the same window.
            </p>
        </x-card>
    </div>

    {{-- War & MMR Charts --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
        <x-card title="War Tempo & Damage (14 Days)">
            <x-slot:menu>
                <x-badge :value="$formatNumber($warsThisWeek) . ' wars launched'" class="badge-error badge-sm" />
                @if (! is_null($warTrend))
                    <x-badge
                        :value="($warTrend >= 0 ? '+' : '') . $formatNumber($warTrend, 1) . '% vs prior week'"
                        :class="$warTrend >= 0 ? 'badge-error badge-sm' : 'badge-success badge-sm'"
                    />
                @endif
            </x-slot:menu>
            <canvas id="warChart" height="220"></canvas>
            <p class="text-base-content/50 text-sm mt-3">
                Tracks wars involving our alliance family only, combining infra destruction and looted cash.
            </p>
        </x-card>

        <x-card title="MMR Readiness">
            <x-slot:menu>
                <x-badge
                    :value="$formatNumber($mmrCoverage, 1) . '% compliant (' . $mmrCompliantCount . '/' . $formatNumber($totalMembers) . ')'"
                    class="badge-info badge-sm"
                />
            </x-slot:menu>
            <canvas id="mmrChart" height="220"></canvas>
            <div class="mt-3">
                <div class="flex justify-between items-center mb-1 text-sm">
                    <span class="text-base-content/60">Coverage to {{ $mmrThreshold }} score</span>
                    <span class="font-semibold">{{ $formatNumber($mmrCoverage, 1) }}%</span>
                </div>
                <x-progress value="{{ (int) min(100, $mmrCoverage) }}" class="progress-info h-2" />
            </div>
        </x-card>
    </div>

    {{-- Resource & Military Charts --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
        <x-card title="Resource Stockpile">
            <x-slot:menu>
                <x-badge :value="'≈ ' . $formatMoney($resourceTotalValue, 0) . ' total'" class="badge-neutral badge-sm" />
            </x-slot:menu>
            <canvas id="resourceChart" height="240"></canvas>
            <p class="text-base-content/50 text-sm mt-3 mb-2">
                {{ $latestTradePriceDate ? "Valued with market snapshot {$latestTradePriceDate}." : 'Market snapshot unavailable; displaying raw tonnage.' }}
            </p>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Resource</th>
                            <th class="text-right">Units</th>
                            <th class="text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($resourceValueBreakdown as $resource => $value)
                            @php
                                $resourceKey = is_string($resource) ? $resource : 'Other';
                                $units = $resourceTotals[$resourceKey] ?? null;
                            @endphp
                            <tr>
                                <td class="capitalize">{{ str_replace('_', ' ', $resourceKey) }}</td>
                                <td class="text-right">{{ $units !== null ? $formatNumber($units, $units >= 1000 ? 0 : 2) : '—' }}</td>
                                <td class="text-right">{{ $formatMoney($value, 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Military Readiness">
            <x-slot:menu>
                <x-badge
                     value="Max: 15k soldiers · 1.25k tanks · 75 aircraft · 15 ships · 60 spies"
                    class="badge-warning badge-sm"
                />
            </x-slot:menu>
            <canvas id="militaryChart" height="240"></canvas>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3 text-sm text-base-content/60">
                <div>
                    <p class="font-medium text-base-content mb-2">Current vs capacity</p>
                    <ul class="space-y-1">
                        @foreach ($militaryTotals as $unit => $total)
                            <li class="flex justify-between">
                                <span class="capitalize">{{ str_replace('_', ' ', $unit) }}</span>
                                <span class="font-semibold text-base-content">
                                    {{ $formatNumber($total) }} / {{ $formatNumber($militaryCapacity[$unit]) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <p class="font-medium text-base-content mb-2">Average posture</p>
                    <ul class="space-y-1">
                        <li class="flex justify-between"><span>Soldiers / city</span><span class="font-semibold text-base-content">{{ $formatNumber($militaryPerUnitAverage['soldiers']) }}</span></li>
                        <li class="flex justify-between"><span>Tanks / city</span><span class="font-semibold text-base-content">{{ $formatNumber($militaryPerUnitAverage['tanks']) }}</span></li>
                        <li class="flex justify-between"><span>Aircraft / city</span><span class="font-semibold text-base-content">{{ $formatNumber($militaryPerUnitAverage['aircraft'], 1) }}</span></li>
                        <li class="flex justify-between"><span>Ships / city</span><span class="font-semibold text-base-content">{{ $formatNumber($militaryPerUnitAverage['ships'], 2) }}</span></li>
                        <li class="flex justify-between"><span>Spies / member</span><span class="font-semibold text-base-content">{{ $formatNumber($militaryPerUnitAverage['spies'], 1) }}</span></li>
                    </ul>
                </div>
            </div>
        </x-card>
    </div>

    {{-- Infra, Wealth, Nations --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
        <x-card title="Infrastructure Focus">
            <p class="text-base-content/60 text-sm mb-3">
                Alliance infrastructure totals {{ $formatNumber($totalInfrastructure, 0) }}.
                Power coverage at {{ $formatNumber($powerCoverage, 1) }}% keeps the network ready.
            </p>
            <ul class="divide-y divide-base-300">
                @forelse ($topInfrastructureCities as $city)
                    <li class="flex justify-between items-start py-2.5">
                        <div>
                            <a href="https://politicsandwar.com/city/id={{ $city->id }}" target="_blank" rel="noopener" class="font-semibold text-primary hover:underline text-sm">
                                {{ $city->name }}
                            </a>
                            <span class="text-base-content/50 text-xs block">
                                <a href="https://politicsandwar.com/nation/id={{ $city->nation?->id }}" target="_blank" rel="noopener" class="hover:underline">
                                    {{ $city->nation?->leader_name ?? 'Unknown' }}
                                </a>
                            </span>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold text-sm">{{ $formatNumber($city->infrastructure, 0) }} infra</div>
                            <div class="text-base-content/50 text-xs">{{ $formatNumber($city->land, 0) }} land</div>
                        </div>
                    </li>
                @empty
                    <li class="text-base-content/50 text-sm py-2">No city telemetry captured yet.</li>
                @endforelse
            </ul>
        </x-card>

        <x-card title="Wealth Concentration">
            <p class="text-base-content/60 text-sm mb-3">
                Alliance-wide cash reserves average {{ $formatMoney($cashPerMember, 0) }} per member.
            </p>
            <ul class="divide-y divide-base-300">
                @forelse ($topCashHolders as $holder)
                    <li class="flex justify-between items-start py-2.5">
                        <div>
                            <a href="https://politicsandwar.com/nation/id={{ $holder->nation_id }}" target="_blank" rel="noopener" class="font-semibold text-primary hover:underline text-sm">
                                {{ $holder->leader_name }}
                            </a>
                            <span class="text-base-content/50 text-xs block">{{ $holder->nation_name }}</span>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold text-sm">{{ $formatMoney($holder->money, 0) }}</div>
                            <div class="text-base-content/50 text-xs">{{ optional($holder->snapshot_at)->diffForHumans() ?? 'Snapshot pending' }}</div>
                        </div>
                    </li>
                @empty
                    <li class="text-base-content/50 text-sm py-2">No resource telemetry available yet.</li>
                @endforelse
            </ul>
        </x-card>

        <x-card title="Top Scoring Nations">
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Nation</th>
                            <th class="text-right">Score</th>
                            <th class="text-right">Cities</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($topScoringNations as $nation)
                            <tr>
                                <td>
                                    <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener" class="font-semibold text-primary hover:underline text-sm">
                                        {{ $nation->leader_name }}
                                    </a>
                                    <span class="text-base-content/50 text-xs block">{{ $nation->nation_name }}</span>
                                </td>
                                <td class="text-right text-sm">{{ $formatNumber($nation->score, 2) }}</td>
                                <td class="text-right text-sm">{{ $formatNumber($nation->num_cities) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-base-content/50 text-sm">No nations tracked yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    {{-- Loan & Grant Snapshots --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
        <x-card title="Loan Program Snapshot">
            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
                <dt class="text-base-content/60 text-xs uppercase font-medium">Pending approvals</dt>
                <dd class="text-right font-semibold">{{ $formatNumber($loanStats['pending']) }}</dd>

                <dt class="text-base-content/60 text-xs uppercase font-medium">Active or delinquent</dt>
                <dd class="text-right font-semibold">{{ $formatNumber($loanStats['active']) }}</dd>

                <dt class="text-base-content/60 text-xs uppercase font-medium">Paid-off loans</dt>
                <dd class="text-right font-semibold">{{ $formatNumber($loanStats['paid']) }}</dd>

                <dt class="text-base-content/60 text-xs uppercase font-medium">Outstanding balance</dt>
                <dd class="text-right font-semibold">{{ $formatMoney($loanStats['outstanding_balance'], 0) }}</dd>

                <dt class="text-base-content/60 text-xs uppercase font-medium">Avg interest</dt>
                <dd class="text-right font-semibold">{{ $loanStats['avg_interest'] !== null ? $formatNumber($loanStats['avg_interest'], 2).'%' : '—' }}</dd>

                <dt class="text-base-content/60 text-xs uppercase font-medium">Avg term (weeks)</dt>
                <dd class="text-right font-semibold">{{ $loanStats['avg_term'] !== null ? $formatNumber($loanStats['avg_term'], 1) : '—' }}</dd>
            </dl>
            <p class="text-base-content/50 text-xs mt-3">
                Balances include approved and missed loans across the primary alliance and all offshores.
            </p>
        </x-card>

        <x-card title="Grant Program Snapshot">
            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
                <dt class="text-base-content/60 text-xs uppercase font-medium">Pending applications</dt>
                <dd class="text-right font-semibold">{{ $formatNumber($grantStats['pending']) }}</dd>

                <dt class="text-base-content/60 text-xs uppercase font-medium">Approved this week</dt>
                <dd class="text-right font-semibold">{{ $formatNumber($grantStats['approved_this_week']) }}</dd>

                <dt class="text-base-content/60 text-xs uppercase font-medium">Total approvals</dt>
                <dd class="text-right font-semibold">{{ $formatNumber($grantStats['approved_total']) }}</dd>

                <dt class="text-base-content/60 text-xs uppercase font-medium">Money issued (30d)</dt>
                <dd class="text-right font-semibold">{{ $formatMoney($grantStats['money_disbursed_30d'], 0) }}</dd>
            </dl>
            <p class="text-base-content/50 text-xs mt-3">
                Resource payouts are calculated from the latest 30 days of approved grant disbursements.
            </p>
        </x-card>
    </div>

    {{-- Active Wars & Recent War Outcomes --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
        <x-card title="Active War Rooms">
            <ul class="divide-y divide-base-300">
                @forelse ($activeWarDetails as $war)
                    @php
                        $attIsMember = in_array($war->att_id, $memberNationIds, true);
                        $defIsMember = in_array($war->def_id, $memberNationIds, true);
                    @endphp
                    <li class="py-3">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <a href="https://politicsandwar.com/nation/id={{ $war->att_id }}" target="_blank" rel="noopener" class="font-semibold text-sm text-primary hover:underline">
                                        {{ $war->attacker?->leader_name ?? 'Unknown' }}
                                    </a>
                                    @if ($attIsMember)
                                        <span class="tooltip" data-tip="Alliance member">
                                            <x-icon name="o-shield-check" class="size-3.5 text-primary" />
                                        </span>
                                    @endif
                                </div>
                                <div class="text-base-content/60 text-xs flex items-center gap-1">
                                    vs
                                    <a href="https://politicsandwar.com/nation/id={{ $war->def_id }}" target="_blank" rel="noopener" class="hover:underline">
                                        {{ $war->defender?->leader_name ?? 'Unknown' }}
                                    </a>
                                    @if ($defIsMember)
                                        <span class="tooltip" data-tip="Alliance member">
                                            <x-icon name="o-shield-check" class="size-3 text-primary" />
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right">
                                <x-badge :value="\Illuminate\Support\Str::headline($war->war_type)" class="badge-error badge-xs" />
                                <div class="text-base-content/50 text-xs mt-1">Turns: {{ $formatNumber($war->turns_left) }}</div>
                            </div>
                        </div>
                        <div class="mt-2 text-xs text-base-content/60 space-y-0.5">
                            <div class="flex justify-between">
                                <span>Resistance</span>
                                <span>{{ $war->att_resistance }} / {{ $war->def_resistance }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Points</span>
                                <span>{{ $war->att_points }} - {{ $war->def_points }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Infra loss</span>
                                <span>{{ $formatNumber($war->att_infra_destroyed + $war->def_infra_destroyed, 0) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Bank loot</span>
                                <span>{{ $formatMoney($war->att_money_looted + $war->def_money_looted, 0) }}</span>
                            </div>
                        </div>
                        <a href="https://politicsandwar.com/nation/war/timeline/war={{ $war->id }}" target="_blank" rel="noopener" class="text-xs text-primary hover:underline mt-1 inline-block">
                            View timeline →
                        </a>
                    </li>
                @empty
                    <li class="text-base-content/50 text-sm py-2">No active conflicts at the moment.</li>
                @endforelse
            </ul>
        </x-card>

        <div class="xl:col-span-2">
            <x-card title="Recent War Outcomes">
                <x-slot:menu>
                    <span class="text-base-content/50 text-xs">Last {{ count($recentWars) }} wars involving alliance members</span>
                </x-slot:menu>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Attacker</th>
                                <th>Defender</th>
                                <th class="text-right">Infra Loss</th>
                                <th class="text-right">Bank Loot</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentWars as $war)
                                @php
                                    $attIsMember = in_array($war->att_id, $memberNationIds, true);
                                    $defIsMember = in_array($war->def_id, $memberNationIds, true);
                                @endphp
                                <tr>
                                    <td class="text-xs text-base-content/60">{{ $war->date ? \Carbon\Carbon::parse($war->date)->format('M d') : '—' }}</td>
                                    <td>
                                        <div class="flex items-center gap-1">
                                            <a href="https://politicsandwar.com/nation/id={{ $war->att_id }}" target="_blank" rel="noopener" class="font-semibold text-sm text-primary hover:underline">
                                                {{ $war->attacker?->leader_name ?? 'Unknown' }}
                                            </a>
                                            @if ($attIsMember)
                                                <span class="tooltip" data-tip="Alliance member"><x-icon name="o-shield-check" class="size-3 text-primary" /></span>
                                            @endif
                                            @if ($war->winner_id === $war->att_id)
                                                <x-badge  value="W" class="badge-success badge-xs" />
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-1">
                                            <a href="https://politicsandwar.com/nation/id={{ $war->def_id }}" target="_blank" rel="noopener" class="font-semibold text-sm text-primary hover:underline">
                                                {{ $war->defender?->leader_name ?? 'Unknown' }}
                                            </a>
                                            @if ($defIsMember)
                                                <span class="tooltip" data-tip="Alliance member"><x-icon name="o-shield-check" class="size-3 text-primary" /></span>
                                            @endif
                                            @if ($war->winner_id === $war->def_id)
                                                <x-badge  value="W" class="badge-success badge-xs" />
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-right text-sm">{{ $formatNumber($war->att_infra_destroyed + $war->def_infra_destroyed, 0) }}</td>
                                    <td class="text-right text-sm">{{ $formatMoney($war->att_money_looted + $war->def_money_looted, 0) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-base-content/50 text-sm">No recent wars recorded.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const palette = {
                primary: '#2563eb',
                secondary: '#1d4ed8',
                success: '#16a34a',
                warning: '#f97316',
                danger: '#dc2626',
                info: '#0ea5e9',
                neutral: '#64748b',
            };

            const taxMoneyData = @json($taxMoneyDaily);
            const taxResourceData = @json($taxResourceDaily);
            const warData = @json($warDaily);
            const mmrBuckets = @json($mmrDistribution);
            const resourceBreakdown = @json($resourceValueBreakdown);
            const militaryReadiness = @json($militaryReadiness);

            const numberFormat = (value) => new Intl.NumberFormat('en-US').format(value ?? 0);
            const currencyFormat = (value) => '$' + new Intl.NumberFormat('en-US', {maximumFractionDigits: 0}).format(value ?? 0);

            const ctxTaxMoney = document.getElementById('taxMoneyChart');
            if (ctxTaxMoney) {
                new Chart(ctxTaxMoney, {
                    type: 'line',
                    data: {
                        labels: taxMoneyData.map(item => item.day),
                        datasets: [{
                            label: 'Money',
                            data: taxMoneyData.map(item => item.money),
                            borderColor: palette.success,
                            backgroundColor: palette.success + '33',
                            fill: true,
                            tension: 0.3,
                        }],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label(context) { return `${context.dataset.label}: ${currencyFormat(context.parsed.y)}`; } } },
                        },
                        scales: { y: { beginAtZero: true } },
                    },
                });
            }

            const ctxTaxResource = document.getElementById('taxResourceChart');
            if (ctxTaxResource) {
                new Chart(ctxTaxResource, {
                    type: 'line',
                    data: {
                        labels: taxResourceData.map(item => item.day),
                        datasets: [
                            { label: 'Steel', data: taxResourceData.map(item => item.steel), borderColor: palette.primary, backgroundColor: palette.primary + '22', fill: true, tension: 0.3 },
                            { label: 'Munitions', data: taxResourceData.map(item => item.munitions), borderColor: palette.warning, backgroundColor: palette.warning + '22', fill: true, tension: 0.3 },
                            { label: 'Aluminum', data: taxResourceData.map(item => item.aluminum), borderColor: palette.info, backgroundColor: palette.info + '22', fill: true, tension: 0.3 },
                            { label: 'Food', data: taxResourceData.map(item => item.food), borderColor: palette.neutral, backgroundColor: palette.neutral + '22', fill: true, tension: 0.3 },
                        ],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: true },
                            tooltip: { callbacks: { label(context) { return `${context.dataset.label}: ${numberFormat(context.parsed.y)}`; } } },
                        },
                        scales: { y: { beginAtZero: true } },
                    },
                });
            }

            const ctxWar = document.getElementById('warChart');
            if (ctxWar) {
                new Chart(ctxWar, {
                    data: {
                        labels: warData.map(item => item.day),
                        datasets: [
                            { type: 'bar', label: 'Wars Started', data: warData.map(item => item.wars_started), backgroundColor: palette.danger + '55', borderRadius: 6, yAxisID: 'y' },
                            { type: 'line', label: 'Infra Destroyed', data: warData.map(item => item.infra_destroyed), borderColor: palette.warning, borderWidth: 2, tension: 0.3, yAxisID: 'y1' },
                            { type: 'line', label: 'Money Looted', data: warData.map(item => item.money_looted), borderColor: palette.success, borderDash: [6, 4], borderWidth: 2, tension: 0.3, yAxisID: 'y1' },
                        ],
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: { beginAtZero: true, position: 'left' },
                            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } },
                        },
                        plugins: {
                            legend: { display: true },
                            tooltip: { callbacks: { label(context) {
                                return context.dataset.label === 'Money Looted'
                                    ? `${context.dataset.label}: ${currencyFormat(context.parsed.y)}`
                                    : `${context.dataset.label}: ${numberFormat(context.parsed.y)}`;
                            }}},
                        },
                    },
                });
            }

            const ctxMmr = document.getElementById('mmrChart');
            if (ctxMmr) {
                new Chart(ctxMmr, {
                    type: 'bar',
                    data: {
                        labels: mmrBuckets.map(item => `${item.bucket}s`),
                        datasets: [{ label: 'Nations', data: mmrBuckets.map(item => item.total), backgroundColor: palette.info + '66', borderRadius: 6 }],
                    },
                    options: {
                        responsive: true,
                        scales: { y: { beginAtZero: true } },
                        plugins: { legend: { display: false }, tooltip: { callbacks: { label(context) { return `${context.parsed.y} nations`; } } } },
                    },
                });
            }

            const ctxResource = document.getElementById('resourceChart');
            if (ctxResource) {
                const labels = Object.keys(resourceBreakdown || {});
                const values = Object.values(resourceBreakdown || {});
                new Chart(ctxResource, {
                    type: 'doughnut',
                    data: {
                        labels,
                        datasets: [{ data: values, backgroundColor: [palette.primary, palette.success, palette.info, palette.warning, palette.danger, palette.neutral] }],
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: { callbacks: { label(context) { return `${context.label}: ${currencyFormat(context.parsed)}`; } } },
                        },
                    },
                });
            }

            const ctxMilitary = document.getElementById('militaryChart');
            if (ctxMilitary) {
                const labels = Object.keys(militaryReadiness || {}).map(label => label.replace(/_/g, ' '));
                const readiness = Object.values(militaryReadiness || {});
                new Chart(ctxMilitary, {
                    type: 'radar',
                    data: {
                        labels,
                        datasets: [{ label: 'Readiness %', data: readiness, borderColor: palette.primary, backgroundColor: palette.primary + '33', borderWidth: 2, pointBackgroundColor: palette.primary }],
                    },
                    options: {
                        responsive: true,
                        scales: { r: { beginAtZero: true, suggestedMax: 100, ticks: { callback(value) { return `${value}%`; } } } },
                        plugins: { tooltip: { callbacks: { label(context) { return `${context.label}: ${context.parsed.r}%`; } } } },
                    },
                });
            }
        });
    </script>
@endpush

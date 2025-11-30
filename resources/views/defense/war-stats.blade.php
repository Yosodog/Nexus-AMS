@extends('layouts.main')

@section('content')
    @php
        $totalWars = $activeWars->count() + $pastWars->count();
        $completedWars = $wins + $losses + $draws;
        $winRate = $completedWars > 0 ? round(($wins / $completedWars) * 100, 1) : 0;
        $avgDurationHoursValue = $avgDurationHours ?? 0;
        $avgDurationDays = $avgDurationHoursValue ? round($avgDurationHoursValue / 24, 1) : 0;
    @endphp
    <div class="space-y-6">
        <div class="card bg-gradient-to-br from-primary via-primary/90 to-secondary text-primary-content shadow-xl">
            <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
                <div class="md:col-span-2 space-y-2">
                    <p class="text-xs uppercase tracking-[0.2em] text-primary-content/80">Defense • War Storyboard</p>
                    <h1 class="text-3xl sm:text-4xl font-black">War Stats for {{ $nation->leader_name }}</h1>
                    <p class="max-w-4xl text-sm sm:text-base text-primary-content/80">
                        Track every battle, watch the unit trades, and flex your haul. Live view of active wars plus a deep dive
                        into your full war history with charts tailored for quick decision making.
                    </p>
                </div>
                <div class="w-full">
                    <div class="rounded-2xl bg-base-100/10 border border-primary-content/20 p-4 backdrop-blur">
                        <div class="flex items-center justify-between text-xs uppercase text-primary-content/70">
                            <span>Totals tracked</span>
                            <span>{{ $offensiveCount }} off • {{ $defensiveCount }} def</span>
                        </div>
                        <div class="mt-2 text-5xl font-black leading-none">{{ number_format($totalWars) }}</div>
                        <div class="mt-3 grid grid-cols-2 gap-3 text-sm text-primary-content/80">
                            <div class="p-2 rounded-xl bg-base-100/10 border border-primary-content/10">
                                <p class="text-xs uppercase opacity-80">Win rate</p>
                                <p class="text-lg font-semibold">{{ $winRate }}%</p>
                            </div>
                            <div class="p-2 rounded-xl bg-base-100/10 border border-primary-content/10">
                                <p class="text-xs uppercase opacity-80">Avg length</p>
                                <p class="text-lg font-semibold">{{ $avgDurationDays }}d</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Active wars</div>
                <div class="stat-value text-primary">{{ $activeWars->count() }}</div>
                <div class="stat-desc text-base-content/70">Ongoing engagements right now</div>
            </div>
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Win rate</div>
                <div class="stat-value text-success">{{ $winRate }}%</div>
                <div class="stat-desc text-base-content/70">{{ $wins }}W • {{ $losses }}L • {{ $draws }} pending</div>
            </div>
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Avg war length</div>
                <div class="stat-value text-secondary">{{ $avgDurationDays }}d</div>
                <div class="stat-desc text-base-content/70">({{ number_format($avgDurationHoursValue, 1) }} hours)</div>
            </div>
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Loot hauled</div>
                <div class="stat-value text-info">${{ number_format($lootTotal, 0) }}</div>
                <div class="stat-desc text-base-content/70">Missiles: {{ $missilesUsed }} • Nukes: {{ $nukesUsed }}</div>
            </div>
        </div>

        <form class="card bg-base-100 border border-base-300 shadow-sm" method="GET" action="{{ route('defense.war-stats') }}">
            <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="label-text text-sm font-semibold">Nation ID</label>
                    <div class="join w-full">
                        <input
                            type="number"
                            name="nation_id"
                            value="{{ $filters['nation_id'] }}"
                            class="input input-bordered join-item w-full"
                            placeholder="e.g. 123456"
                        />
                        <button class="btn btn-primary join-item" type="submit">Load</button>
                    </div>
                    @if(! $filters['is_self'])
                        <p class="text-xs text-base-content/70 mt-1">Viewing another nation. Clear to return to yours.</p>
                    @endif
                </div>
                <div>
                    <label class="label-text text-sm font-semibold">From</label>
                    <input type="date" name="from" class="input input-bordered w-full" value="{{ $filters['from'] }}">
                </div>
                <div>
                    <label class="label-text text-sm font-semibold">To</label>
                    <input type="date" name="to" class="input input-bordered w-full" value="{{ $filters['to'] }}">
                </div>
                <div class="flex items-end gap-3">
                    <button class="btn btn-outline w-full" type="button" onclick="window.location='{{ route('defense.war-stats') }}'">
                        Reset to me (last 30d)
                    </button>
                </div>
            </div>
        </form>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <div class="card bg-base-100 border border-base-300 shadow-sm xl:col-span-2">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Damage over time</p>
                            <h2 class="card-title">Infrastructure swing (value)</h2>
                        </div>
                        <div class="badge badge-outline text-xs">
                            {{ count($timeline['labels']) }} battle days
                        </div>
                    </div>
                    <canvas id="impactChart" class="mt-4"></canvas>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Resource burn</p>
                    <h2 class="card-title">Fuel + Ammo</h2>
                    <div class="grid grid-cols-2 gap-3 text-sm mt-2">
                        @foreach($resourceUsage as $resource => $value)
                            <div class="p-3 rounded-xl bg-base-200/60 border border-base-300 flex flex-col gap-1">
                                <div class="flex items-center justify-between text-xs uppercase text-base-content/60">
                                    <span>{{ $resource }}</span>
                                    <span class="badge badge-ghost badge-sm">{{ $offensiveCount }} off / {{ $defensiveCount }} def</span>
                                </div>
                                <div class="text-lg font-semibold">{{ number_format($value ?? 0, 2) }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-6">
                        <canvas id="resourceChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Unit trades</p>
                    <h2 class="card-title">Kills vs losses (normalized)</h2>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        @foreach($unitScoreExchange['inflicted'] as $unit => $score)
                            <div class="p-3 rounded-xl bg-base-200/60 border border-base-300">
                                <div class="flex items-center justify-between text-xs uppercase text-base-content/60">
                                    <span>{{ ucfirst($unit) }}</span>
                                    <span class="badge badge-outline badge-xs">Score</span>
                                </div>
                                @php
                                    $lost = $unitScoreExchange['lost'][$unit] ?? 0;
                                    $net = $score - $lost;
                                    $rawInflicted = $unitExchange['inflicted'][$unit] ?? 0;
                                    $rawLost = $unitExchange['lost'][$unit] ?? 0;
                                    $rawNet = $rawInflicted - $rawLost;
                                @endphp
                                <div class="text-lg font-semibold">{{ number_format($rawInflicted) }} dealt</div>
                                <div class="text-xs text-base-content/70 mb-1">
                                    Lost {{ number_format($rawLost) }} • Net {{ $rawNet >= 0 ? '+' : '' }}{{ number_format($rawNet) }}
                                </div>
                                <div class="text-xs text-base-content/60 border-t border-base-300 pt-2">
                                    Power: {{ number_format($score, 2) }} | Lost {{ number_format($lost, 2) }} | Net {{ $net >= 0 ? '+' : '' }}{{ number_format($net, 2) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-6">
                        <canvas id="unitExchangeChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body !block space-y-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-base-content/60 m-0 mb-1 leading-none">War mix</p>
                    <h2 class="card-title">War types</h2>
                    <div class="mt-2">
                        <canvas id="warTypeChart" class="w-full max-h-56"></canvas>
                    </div>
                    <div class="mt-4 space-y-2 text-sm">
                        @forelse($warTypeBreakdown as $type => $count)
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-primary/70"></span>
                                <span class="font-semibold uppercase">{{ $type }}</span>
                                <span class="text-base-content/70">({{ $count }} wars)</span>
                            </div>
                        @empty
                            <p class="text-base-content/70">No war type data yet.</p>
                        @endforelse
                    </div>
                    <div class="mt-4 p-3 rounded-xl bg-base-200/70 border border-base-300">
                        <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Infra impact</p>
                        <p class="text-sm flex items-center gap-2">
                            <span class="badge badge-success badge-sm">Inflicted</span>
                            ${{ number_format($infraExchange['value']['inflicted'], 0) }}
                            <span class="text-base-content/60">vs</span>
                            <span class="badge badge-error badge-sm">Taken</span>
                            ${{ number_format($infraExchange['value']['taken'], 0) }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body !block space-y-3">
                    <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Favorite opponents</p>
                    <h2 class="card-title">Who you keep meeting</h2>
                    <div class="space-y-3">
                        @forelse($opponents as $opponent)
                            <div class="p-3 rounded-xl bg-base-200/60 border border-base-300">
                                <div class="flex items-center justify-between gap-2">
                                    <div>
                                        <p class="font-semibold">{{ $opponent['name'] }}</p>
                                        <p class="text-xs text-base-content/70">{{ $opponent['count'] }} encounters</p>
                                    </div>
                                    <div class="flex gap-1">
                                        <span class="badge badge-outline badge-sm">{{ $opponent['roles']['Offense'] ?? 0 }} off</span>
                                        <span class="badge badge-outline badge-sm">{{ $opponent['roles']['Defense'] ?? 0 }} def</span>
                                    </div>
                                </div>
                                <div class="mt-2 text-xs text-base-content/70 flex gap-2">
                                    <span class="badge badge-success badge-xs">{{ $opponent['results']['Win'] ?? 0 }} wins</span>
                                    <span class="badge badge-error badge-xs">{{ $opponent['results']['Loss'] ?? 0 }} losses</span>
                                    <span class="badge badge-ghost badge-xs">{{ $opponent['results']['Active'] ?? 0 }} active</span>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No repeat opponents yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <div class="xl:col-span-2 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold">Active engagements</h2>
                    <span class="badge badge-primary badge-outline">{{ $activeWars->count() }} live</span>
                </div>
                @forelse($activeWars as $war)
                    @php
                        $isAttacker = $war->att_id === $nation->id;
                        $opponent = $isAttacker ? $war->defender : $war->attacker;
                        $ourResistance = $isAttacker ? ($war->att_resistance ?? 0) : ($war->def_resistance ?? 0);
                        $theirResistance = $isAttacker ? ($war->def_resistance ?? 0) : ($war->att_resistance ?? 0);
                        $started = \Carbon\Carbon::parse($war->date)->diffForHumans();
                    @endphp
                    <div class="card bg-base-100 border border-base-300 shadow-sm">
                        <div class="card-body gap-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">
                                        {{ $isAttacker ? 'Offense' : 'Defense' }} • {{ strtoupper($war->war_type) }}
                                    </p>
                                    <h3 class="text-lg font-semibold">
                                        vs {{ $opponent?->leader_name ?? 'Unknown' }}
                                        <span class="badge badge-ghost badge-sm ml-2">#{{ $opponent->id ?? '???' }}</span>
                                    </h3>
                                    <p class="text-sm text-base-content/70">Started {{ $started }}</p>
                                </div>
                                <div class="text-right space-y-1">
                                    <span class="badge badge-outline">{{ $war->reason ?: 'No reason given' }}</span>
                                    <p class="text-xs text-base-content/60">Turns left: {{ $war->turns_left ?? 0 }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <p class="text-xs uppercase text-base-content/60 mb-1">Your resistance</p>
                                    <progress class="progress progress-primary w-full" value="{{ $ourResistance }}" max="100"></progress>
                                    <p class="text-xs text-base-content/70 mt-1">{{ $ourResistance }} / 100</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase text-base-content/60 mb-1">Opponent resistance</p>
                                    <progress class="progress progress-secondary w-full" value="{{ $theirResistance }}" max="100"></progress>
                                    <p class="text-xs text-base-content/70 mt-1">{{ $theirResistance }} / 100</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                                <div class="p-3 rounded-xl bg-base-200/70 border border-base-300">
                                    <p class="text-xs uppercase text-base-content/60">Infra impact</p>
                                    <p class="font-semibold text-success">+${{ number_format($isAttacker ? ($war->att_infra_destroyed_value ?? 0) : ($war->def_infra_destroyed_value ?? 0), 0) }}</p>
                                    <p class="text-xs text-base-content/70">Taken: ${{ number_format($isAttacker ? ($war->def_infra_destroyed_value ?? 0) : ($war->att_infra_destroyed_value ?? 0), 0) }}</p>
                                </div>
                                <div class="p-3 rounded-xl bg-base-200/70 border border-base-300">
                                    <p class="text-xs uppercase text-base-content/60">Loot so far</p>
                                    <p class="font-semibold text-info">${{ number_format($isAttacker ? ($war->att_money_looted ?? 0) : ($war->def_money_looted ?? 0), 0) }}</p>
                                    <p class="text-xs text-base-content/70">Points: {{ $isAttacker ? ($war->att_points ?? 0) : ($war->def_points ?? 0) }}</p>
                                </div>
                                <div class="p-3 rounded-xl bg-base-200/70 border border-base-300">
                                    <p class="text-xs uppercase text-base-content/60">Missiles</p>
                                    <p class="font-semibold">{{ $isAttacker ? ($war->att_missiles_used ?? 0) : ($war->def_missiles_used ?? 0) }}</p>
                                    <p class="text-xs text-base-content/70">Nukes: {{ $isAttacker ? ($war->att_nukes_used ?? 0) : ($war->def_nukes_used ?? 0) }}</p>
                                </div>
                                <div class="p-3 rounded-xl bg-base-200/70 border border-base-300">
                                    <p class="text-xs uppercase text-base-content/60">Air/Ground</p>
                                    <p class="font-semibold">
                                        {{ number_format($isAttacker ? ($war->def_aircraft_lost ?? 0) : ($war->att_aircraft_lost ?? 0)) }} air dmg
                                    </p>
                                    <p class="text-xs text-base-content/70">
                                        {{ number_format($isAttacker ? ($war->def_soldiers_lost ?? 0) : ($war->att_soldiers_lost ?? 0)) }} soldiers hit
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-6 rounded-2xl bg-base-100 border border-dashed border-base-300 text-base-content/70">
                        No active wars right now. Enjoy the peace — or queue up a raid.
                    </div>
                @endforelse
            </div>
            <div class="space-y-3">
                <div class="card bg-base-100 border border-base-300 shadow-sm">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between">
                            <h2 class="card-title">Impact snapshot</h2>
                            <span class="badge badge-ghost">{{ $totalWars }} wars</span>
                        </div>
                        <div class="p-3 rounded-xl bg-base-200/60 border border-base-300">
                            <div class="flex items-center justify-between text-sm">
                                <span>Infra destroyed (value)</span>
                                <span class="font-semibold text-success">+${{ number_format($infraExchange['value']['inflicted'], 0) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-base-content/70">
                                <span>Infra lost</span>
                                <span>${{ number_format($infraExchange['value']['taken'], 0) }}</span>
                            </div>
                        </div>
                        <div class="p-3 rounded-xl bg-base-200/60 border border-base-300">
                            <div class="flex items-center justify-between text-sm">
                                <span>Raw infra damage</span>
                                <span class="font-semibold text-primary">{{ number_format($infraExchange['raw']['inflicted'], 0) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-base-content/70">
                                <span>Lost</span>
                                <span>{{ number_format($infraExchange['raw']['taken'], 0) }}</span>
                            </div>
                        </div>
                        <div class="p-3 rounded-xl bg-base-200/60 border border-base-300">
                            <div class="flex items-center justify-between text-sm">
                                <span>Loot captured</span>
                                <span class="font-semibold text-info">${{ number_format($lootTotal, 0) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-base-content/70">
                                <span>Missiles + nukes</span>
                                <span>{{ $missilesUsed }} / {{ $nukesUsed }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card bg-base-100 border border-base-300 shadow-sm">
                    <div class="card-body gap-3">
                        <h2 class="card-title">Recent wars</h2>
                        <div class="space-y-2 max-h-[460px] overflow-y-auto pr-1">
                            @forelse($recentWars as $war)
                                @php
                                    $isAttacker = $war->att_id === $nation->id;
                                    $opponent = $isAttacker ? $war->defender : $war->attacker;
                                    $status = $war->end_date
                                        ? ((int) $war->winner_id === $nation->id ? 'Win' : ($war->winner_id ? 'Loss' : 'Draw'))
                                        : 'Active';
                                    $statusClass = match ($status) {
                                        'Win' => 'badge-success',
                                        'Loss' => 'badge-error',
                                        'Draw' => 'badge-ghost',
                                        default => 'badge-primary',
                                    };
                                @endphp
                                <div class="p-3 rounded-xl bg-base-200/60 border border-base-300 flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold">
                                            {{ $isAttacker ? 'Offense' : 'Defense' }} vs {{ $opponent?->leader_name ?? 'Unknown' }}
                                        </p>
                                        <p class="text-xs text-base-content/70">
                                            {{ strtoupper($war->war_type) }} • {{ \Carbon\Carbon::parse($war->date)->format('M d, H:i') }}
                                        </p>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        <span class="badge {{ $statusClass }} badge-outline">{{ $status }}</span>
                                        <span class="text-xs text-base-content/60">
                                            Loot: ${{ number_format($isAttacker ? ($war->att_money_looted ?? 0) : ($war->def_money_looted ?? 0), 0) }}
                                        </span>
                                    </div>
                                </div>
                            @empty
                                <p class="text-base-content/70">No war history yet.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            const impactData = @json($timeline);
            const unitScoreExchange = @json($unitScoreExchange);
            const resourceUsage = @json($resourceUsage);
            const warTypeBreakdown = @json($warTypeBreakdown);

            const impactCtx = document.getElementById('impactChart');
            if (impactCtx) {
                new Chart(impactCtx, {
                    type: 'line',
                    data: {
                        labels: impactData.labels,
                        datasets: [
                            {
                                label: 'Inflicted ($ value)',
                                data: impactData.inflicted,
                                borderColor: '#22c55e',
                                backgroundColor: 'rgba(34,197,94,0.15)',
                                tension: 0.35,
                                fill: true,
                            },
                            {
                                label: 'Taken ($ value)',
                                data: impactData.taken,
                                borderColor: '#ef4444',
                                backgroundColor: 'rgba(239,68,68,0.1)',
                                borderDash: [6, 4],
                                tension: 0.35,
                                fill: true,
                            },
                        ],
                    },
                    options: {
                        plugins: {
                            legend: { display: true },
                        },
                        scales: {
                            y: {
                                ticks: { callback: value => `$${Number(value).toLocaleString()}` },
                                grid: { color: 'rgba(0,0,0,0.05)' },
                            },
                        },
                    },
                });
            }

            const resourceCtx = document.getElementById('resourceChart');
            if (resourceCtx) {
                const labels = Object.keys(resourceUsage);
                const data = Object.values(resourceUsage).map(v => Number(v));

                new Chart(resourceCtx, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            {
                                label: 'Consumed',
                                data,
                                backgroundColor: ['#fde68a', '#fca5a5', '#60a5fa', '#a78bfa'],
                                borderRadius: 10,
                            },
                        ],
                    },
                    options: {
                        indexAxis: 'y',
                        scales: {
                            x: { ticks: { callback: value => Number(value).toLocaleString() } },
                        },
                        plugins: { legend: { display: false } },
                    },
                });
            }

            const unitCtx = document.getElementById('unitExchangeChart');
            if (unitCtx) {
                const labels = Object.keys(unitScoreExchange.inflicted);
                const inflicted = labels.map(l => Number(unitScoreExchange.inflicted[l]));
                const lost = labels.map(l => Number(unitScoreExchange.lost[l]));

                new Chart(unitCtx, {
                    type: 'radar',
                    data: {
                        labels,
                        datasets: [
                            {
                                label: 'Inflicted',
                                data: inflicted,
                                backgroundColor: 'rgba(34,197,94,0.2)',
                                borderColor: '#22c55e',
                            },
                            {
                                label: 'Lost',
                                data: lost,
                                backgroundColor: 'rgba(239,68,68,0.15)',
                                borderColor: '#ef4444',
                            },
                        ],
                    },
                    options: {
                        scales: {
                            r: {
                                ticks: { display: false },
                                angleLines: { color: 'rgba(0,0,0,0.08)' },
                            },
                        },
                    },
                });
            }

            const warTypeCtx = document.getElementById('warTypeChart');
            if (warTypeCtx) {
                const labels = Object.keys(warTypeBreakdown);
                const data = Object.values(warTypeBreakdown);
                new Chart(warTypeCtx, {
                    type: 'doughnut',
                    data: {
                        labels,
                        datasets: [
                            {
                                data,
                                backgroundColor: ['#38bdf8', '#34d399', '#f472b6', '#fbbf24', '#a78bfa', '#f97316'],
                            },
                        ],
                    },
                    options: {
                        plugins: {
                            legend: { display: false },
                        },
                        cutout: '65%',
                    },
                });
            }
        </script>
    @endpush
@endsection

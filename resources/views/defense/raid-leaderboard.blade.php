@extends('layouts.main')

@section('content')
    @php
        $lootLeaders = $leaderboards['loot'] ?? [];
        $lootRateLeaders = $leaderboards['loot_rate'] ?? [];
        $lootCloserLeaders = $leaderboards['loot_closer'] ?? [];
        $infraLeaders = $leaderboards['infra'] ?? [];
        $infraRateLeaders = $leaderboards['infra_rate'] ?? [];
        $killLeaders = $leaderboards['kills'] ?? [];
        $killRateLeaders = $leaderboards['kill_rate'] ?? [];
        $victoryLeaders = $leaderboards['victories'] ?? [];
        $attackLeaders = $leaderboards['attacks'] ?? [];
        $moneyLeaders = $leaderboards['money'] ?? [];
        $resourceTotals = $totals['resources_looted'] ?? [];
        $resourceValues = $totals['resource_values'] ?? [];
        $lootTimeline = $charts['loot_timeline'] ?? ['labels' => [], 'values' => []];
        $infraTimeline = $charts['infra_timeline'] ?? ['labels' => [], 'values' => []];
        $attackTimeline = $charts['attack_timeline'] ?? ['labels' => [], 'values' => []];
        $resourceMix = $charts['resource_mix'] ?? ['labels' => [], 'values' => []];
        $topLoot = $charts['top_loot'] ?? ['labels' => [], 'values' => []];
        $topInfra = $charts['top_infra'] ?? ['labels' => [], 'values' => []];
        $topEfficiency = $charts['top_efficiency'] ?? ['labels' => [], 'values' => []];
        $fromLabel = \Carbon\Carbon::parse($filters['from'])->format('M d, Y');
        $toLabel = \Carbon\Carbon::parse($filters['to'])->format('M d, Y');
        $topLooter = $totals['top_looter'] ?? null;
        $topCloser = $totals['top_closer'] ?? null;
        $selfStats = $self_stats ?? null;
    @endphp

    <div class="space-y-6">
        <div class="card bg-gradient-to-br from-secondary via-primary/80 to-accent text-primary-content shadow-xl overflow-hidden">
            <div class="card-body grid grid-cols-1 lg:grid-cols-3 gap-6 items-center">
                <div class="lg:col-span-2 space-y-3">
                    <p class="text-xs uppercase tracking-[0.3em] text-primary-content/70">Defense • Raid Leaderboard</p>
                    <h1 class="text-3xl sm:text-4xl font-black">Raid Hall of Fame</h1>
                    <p class="text-sm sm:text-base text-primary-content/80 max-w-3xl">
                        Who is printing cash and wiping cities? This board ranks raiders by loot value, infra damage, and battlefield
                        dominance. Bring receipts and climb the ladder.
                    </p>
                </div>
                <div class="w-full">
                    <div class="rounded-2xl bg-base-100/10 border border-primary-content/20 p-4 backdrop-blur">
                        <div class="flex items-center justify-between text-xs uppercase text-primary-content/70">
                            <span>Alliance totals</span>
                            <span>{{ $fromLabel }} - {{ $toLabel }}</span>
                        </div>
                        <div class="mt-3 text-4xl font-black leading-none">
                            ${{ number_format($totals['loot_value'] ?? 0, 0) }}
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-3 text-sm text-primary-content/80">
                            <div class="p-2 rounded-xl bg-base-100/10 border border-primary-content/10">
                                <p class="text-xs uppercase opacity-80">Infra burned</p>
                                <p class="text-lg font-semibold">${{ number_format($totals['infra_destroyed_value'] ?? 0, 0) }}</p>
                            </div>
                            <div class="p-2 rounded-xl bg-base-100/10 border border-primary-content/10">
                                <p class="text-xs uppercase opacity-80">Victories</p>
                                <p class="text-lg font-semibold">{{ number_format($totals['victories'] ?? 0) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Total loot value</div>
                <div class="stat-value text-secondary">${{ number_format($totals['loot_value'] ?? 0, 0) }}</div>
                <div class="stat-desc text-base-content/70">Money + resources at 24h avg</div>
            </div>
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Infra destroyed</div>
                <div class="stat-value text-primary">${{ number_format($totals['infra_destroyed_value'] ?? 0, 0) }}</div>
                <div class="stat-desc text-base-content/70">Value of city damage</div>
            </div>
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Raid actions</div>
                <div class="stat-value text-accent">{{ number_format($totals['attacks'] ?? 0) }}</div>
                <div class="stat-desc text-base-content/70">Total attacks by members</div>
            </div>
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Victories</div>
                <div class="stat-value text-info">{{ number_format($totals['victories'] ?? 0) }}</div>
                <div class="stat-desc text-base-content/70">Confirmed victory hits</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Loot per day</div>
                <div class="stat-value text-secondary">${{ number_format($totals['loot_value_per_day'] ?? 0, 0) }}</div>
                <div class="stat-desc text-base-content/70">Average daily haul</div>
            </div>
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Loot per attack</div>
                <div class="stat-value text-primary">${{ number_format($totals['avg_loot_per_attack'] ?? 0, 0) }}</div>
                <div class="stat-desc text-base-content/70">Efficiency score</div>
            </div>
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Loot per victory</div>
                <div class="stat-value text-accent">${{ number_format($totals['avg_loot_per_victory'] ?? 0, 0) }}</div>
                <div class="stat-desc text-base-content/70">Finisher reward</div>
            </div>
            <div class="stat bg-base-100/70 border border-base-300 shadow-sm rounded-2xl">
                <div class="stat-title text-base-content/70">Kill score total</div>
                <div class="stat-value text-info">{{ number_format($totals['kills_score'] ?? 0, 2) }}</div>
                <div class="stat-desc text-base-content/70">Weighted unit kills</div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">MVP</p>
                    <h2 class="card-title">Top Looter</h2>
                    @if($topLooter)
                        <p class="text-2xl font-bold">{{ $topLooter['leader_name'] }}</p>
                        <p class="text-sm text-base-content/60">{{ $topLooter['nation_name'] }}</p>
                        <p class="mt-2 text-lg font-semibold">${{ number_format($topLooter['loot_value'], 0) }}</p>
                        <p class="text-xs text-base-content/60">{{ $topLooter['victories'] }} victories • {{ $topLooter['attacks'] }} attacks</p>
                    @else
                        <p class="text-base-content/70">No raiders yet.</p>
                    @endif
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Closer</p>
                    <h2 class="card-title">Most Victories</h2>
                    @if($topCloser)
                        <p class="text-2xl font-bold">{{ $topCloser['leader_name'] }}</p>
                        <p class="text-sm text-base-content/60">{{ $topCloser['nation_name'] }}</p>
                        <p class="mt-2 text-lg font-semibold">{{ number_format($topCloser['victories']) }} wins</p>
                        <p class="text-xs text-base-content/60">${{ number_format($topCloser['loot_value'], 0) }} loot value</p>
                    @else
                        <p class="text-base-content/70">No victories yet.</p>
                    @endif
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Damage</p>
                    <h2 class="card-title">Infra per Attack</h2>
                    <p class="text-3xl font-bold">${{ number_format($totals['avg_infra_per_attack'] ?? 0, 0) }}</p>
                    <p class="text-xs text-base-content/60">Average value burned per hit</p>
                </div>
            </div>
        </div>

        <form class="card bg-base-100 border border-base-300 shadow-sm" method="GET" action="{{ route('defense.raid-leaderboard') }}">
            <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="label-text text-sm font-semibold">From</label>
                    <input type="date" name="from" class="input input-bordered w-full" value="{{ $filters['from'] }}">
                </div>
                <div>
                    <label class="label-text text-sm font-semibold">To</label>
                    <input type="date" name="to" class="input input-bordered w-full" value="{{ $filters['to'] }}">
                </div>
                <div class="flex items-end gap-3">
                    <button class="btn btn-primary w-full" type="submit">Update Range</button>
                </div>
                <div class="flex items-end gap-3">
                    <button class="btn btn-outline w-full" type="button" onclick="window.location='{{ route('defense.raid-leaderboard') }}'">
                        Reset (last 30d)
                    </button>
                </div>
            </div>
        </form>

        @if($selfStats)
            @php
                $memberCount = $selfStats['member_count'] ?? 0;
                $lootRank = $selfStats['ranks']['loot_value'] ?? null;
                $victoryRank = $selfStats['ranks']['victories'] ?? null;
                $lootRateRank = $selfStats['ranks']['loot_per_attack'] ?? null;
                $killRateRank = $selfStats['ranks']['kill_score_per_attack'] ?? null;
                $lootPercentile = $lootRank && $memberCount > 0
                    ? round(((($memberCount - $lootRank) / $memberCount) * 100), 1)
                    : 0;
            @endphp
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <div class="xl:col-span-2 space-y-3">
                        <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Your raid performance</p>
                        <h2 class="card-title text-2xl">Personal Trophy Case</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 rounded-2xl bg-secondary/10 border border-secondary/30">
                                <p class="text-xs uppercase text-base-content/70">Loot value</p>
                                <p class="text-2xl font-bold text-secondary">${{ number_format($selfStats['stats']['loot_value'] ?? 0, 0) }}</p>
                                <p class="text-xs text-base-content/60">Rank #{{ $lootRank ?? '-' }} of {{ $memberCount }} • Top {{ $lootPercentile }}%</p>
                            </div>
                            <div class="p-4 rounded-2xl bg-primary/10 border border-primary/30">
                                <p class="text-xs uppercase text-base-content/70">Victories</p>
                                <p class="text-2xl font-bold text-primary">{{ number_format($selfStats['stats']['victories'] ?? 0) }}</p>
                                <p class="text-xs text-base-content/60">Rank #{{ $victoryRank ?? '-' }} • {{ number_format($selfStats['stats']['attacks'] ?? 0) }} attacks</p>
                            </div>
                            <div class="p-4 rounded-2xl bg-accent/10 border border-accent/30">
                                <p class="text-xs uppercase text-base-content/70">Loot per attack</p>
                                <p class="text-2xl font-bold text-accent">${{ number_format($selfStats['stats']['loot_per_attack'] ?? 0, 0) }}</p>
                                <p class="text-xs text-base-content/60">Rank #{{ $lootRateRank ?? '-' }}</p>
                            </div>
                            <div class="p-4 rounded-2xl bg-info/10 border border-info/30">
                                <p class="text-xs uppercase text-base-content/70">Kill score per attack</p>
                                <p class="text-2xl font-bold text-info">{{ number_format($selfStats['stats']['kill_score_per_attack'] ?? 0, 2) }}</p>
                                <p class="text-xs text-base-content/60">Rank #{{ $killRateRank ?? '-' }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div class="p-4 rounded-2xl bg-base-200/60 border border-base-300">
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Your share of loot</p>
                            <p class="text-3xl font-bold text-secondary">{{ number_format($selfStats['loot_share'] ?? 0, 2) }}%</p>
                            <p class="text-xs text-base-content/60">Of alliance loot value</p>
                        </div>
                        <div class="p-4 rounded-2xl bg-base-200/60 border border-base-300">
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Infra damage</p>
                            <p class="text-2xl font-bold text-primary">${{ number_format($selfStats['stats']['infra_destroyed_value'] ?? 0, 0) }}</p>
                            <p class="text-xs text-base-content/60">{{ number_format($selfStats['stats']['infra_destroyed'] ?? 0, 2) }} infra destroyed</p>
                        </div>
                        <div class="p-4 rounded-2xl bg-base-200/60 border border-base-300">
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Unit score</p>
                            <p class="text-2xl font-bold text-accent">{{ number_format($selfStats['stats']['unit_score'] ?? 0, 2) }}</p>
                            <p class="text-xs text-base-content/60">Weighted kills total</p>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 items-stretch">
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Loot velocity</p>
                            <h2 class="card-title">Daily loot value</h2>
                        </div>
                        <span class="badge badge-outline text-xs">24h avg pricing</span>
                    </div>
                    <canvas id="lootTimelineChart" class="mt-4"></canvas>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">City damage</p>
                            <h2 class="card-title">Infra destroyed value</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Daily totals</span>
                    </div>
                    <canvas id="infraTimelineChart" class="mt-4"></canvas>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Tempo</p>
                            <h2 class="card-title">Daily attacks</h2>
                        </div>
                        <span class="badge badge-outline text-xs">All members</span>
                    </div>
                    <div class="h-64">
                        <canvas id="attackTimelineChart" class="h-full"></canvas>
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Raid momentum</p>
                            <h2 class="card-title">Peak performance</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Highlights</span>
                    </div>
                    <div class="mt-4 grid grid-cols-1 gap-3">
                        <div class="p-4 rounded-2xl bg-base-200/60 border border-base-300">
                            <p class="text-xs uppercase text-base-content/60">Best loot day</p>
                            <p class="text-2xl font-bold text-secondary">
                                @if($totals['best_loot_day'])
                                    ${{ number_format($totals['best_loot_day']['value'], 0) }}
                                @else
                                    $0
                                @endif
                            </p>
                            <p class="text-xs text-base-content/60">
                                {{ $totals['best_loot_day']['label'] ?? 'No data yet' }}
                            </p>
                        </div>
                        <div class="p-4 rounded-2xl bg-base-200/60 border border-base-300">
                            <p class="text-xs uppercase text-base-content/60">Best attack day</p>
                            <p class="text-2xl font-bold text-primary">
                                {{ number_format($totals['best_attack_day']['value'] ?? 0) }} hits
                            </p>
                            <p class="text-xs text-base-content/60">
                                {{ $totals['best_attack_day']['label'] ?? 'No data yet' }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="p-4 rounded-2xl bg-secondary/10 border border-secondary/30">
                                <p class="text-xs uppercase text-base-content/70">Resource share</p>
                                <p class="text-xl font-bold text-secondary">{{ number_format($totals['resource_share_pct'] ?? 0, 2) }}%</p>
                            </div>
                            <div class="p-4 rounded-2xl bg-primary/10 border border-primary/30">
                                <p class="text-xs uppercase text-base-content/70">Money share</p>
                                <p class="text-xl font-bold text-primary">{{ number_format($totals['money_share_pct'] ?? 0, 2) }}%</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Loot leaderboard</p>
                            <h2 class="card-title">Top Looters</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Value</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($lootLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-secondary/20 text-secondary flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">${{ number_format($row['loot_value'], 0) }}</p>
                                    <p class="text-xs text-base-content/60">{{ $row['victories'] }} victories</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No raids recorded for this range.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Infra leaderboard</p>
                            <h2 class="card-title">City Wreckers</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Value</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($infraLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-primary/20 text-primary flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">${{ number_format($row['infra_destroyed_value'], 0) }}</p>
                                    <p class="text-xs text-base-content/60">{{ number_format($row['infra_destroyed'], 2) }} infra</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No infra damage recorded yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Kill leaderboard</p>
                            <h2 class="card-title">Unit Takers</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Score</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($killLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-accent/20 text-accent flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">{{ number_format($row['unit_score'], 2) }} score</p>
                                    <p class="text-xs text-base-content/60">
                                        {{ number_format($row['soldiers_killed']) }}s • {{ number_format($row['tanks_killed']) }}t •
                                        {{ number_format($row['aircraft_killed']) }}a • {{ number_format($row['ships_killed']) }}sh
                                    </p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No kills recorded yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Victory leaderboard</p>
                            <h2 class="card-title">Closers</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Victories</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($victoryLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-info/20 text-info flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">{{ number_format($row['victories']) }} wins</p>
                                    <p class="text-xs text-base-content/60">${{ number_format($row['loot_value'], 0) }} loot value</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No victories recorded yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Efficiency</p>
                            <h2 class="card-title">Loot per Attack</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Value</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($lootRateLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-secondary/20 text-secondary flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">${{ number_format($row['loot_per_attack'], 0) }}</p>
                                    <p class="text-xs text-base-content/60">{{ $row['attacks'] }} attacks</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No raid efficiency data yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Closers</p>
                            <h2 class="card-title">Loot per Victory</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Value</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($lootCloserLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-accent/20 text-accent flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">${{ number_format($row['loot_per_victory'], 0) }}</p>
                                    <p class="text-xs text-base-content/60">{{ $row['victories'] }} victories</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No finisher data yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Hitters</p>
                            <h2 class="card-title">Most Attacks</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Volume</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($attackLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-info/20 text-info flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">{{ number_format($row['attacks']) }} attacks</p>
                                    <p class="text-xs text-base-content/60">${{ number_format($row['loot_value'], 0) }} loot value</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No attack volume yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Pressure</p>
                            <h2 class="card-title">Infra per Attack</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Value</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($infraRateLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-primary/20 text-primary flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">${{ number_format($row['infra_per_attack'], 0) }}</p>
                                    <p class="text-xs text-base-content/60">{{ $row['attacks'] }} attacks</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No infra efficiency yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Kill rate</p>
                            <h2 class="card-title">Kill Score per Attack</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Score</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($killRateLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-accent/20 text-accent flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">{{ number_format($row['kill_score_per_attack'], 2) }}</p>
                                    <p class="text-xs text-base-content/60">{{ $row['attacks'] }} attacks</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No kill efficiency yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Money bags</p>
                            <h2 class="card-title">Cash Looted</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Money</span>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($moneyLeaders as $row)
                            <div class="flex items-center justify-between rounded-xl bg-base-200/60 border border-base-300 px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-warning/20 text-warning flex items-center justify-center font-bold">
                                        {{ $row['rank'] }}
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ $row['leader_name'] }}</p>
                                        <p class="text-xs text-base-content/60">{{ $row['nation_name'] }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">${{ number_format($row['money_looted'], 0) }}</p>
                                    <p class="text-xs text-base-content/60">{{ $row['victories'] }} victories</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-base-content/70">No cash looted yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="card bg-base-100 border border-base-300 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Top charts</p>
                            <h2 class="card-title">Leaderboard Spotlights</h2>
                        </div>
                        <span class="badge badge-outline text-xs">Top 6</span>
                    </div>
                    <div class="mt-4 space-y-4">
                        <div>
                            <p class="text-xs uppercase text-base-content/60">Loot value</p>
                            <canvas id="topLootChart" class="mt-2"></canvas>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-base-content/60">Infra value</p>
                            <canvas id="topInfraChart" class="mt-2"></canvas>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-base-content/60">Efficiency</p>
                            <canvas id="topEfficiencyChart" class="mt-2"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300 shadow-sm">
            <div class="card-body">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Loot breakdown</p>
                        <h2 class="card-title">Resources hauled (all members)</h2>
                    </div>
                    <span class="badge badge-ghost text-xs">{{ $fromLabel }} - {{ $toLabel }}</span>
                </div>
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-4">
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
                        @forelse($resourceTotals as $resource => $amount)
                            <div class="p-3 rounded-xl bg-base-200/60 border border-base-300">
                                <p class="text-xs uppercase text-base-content/60">{{ $resource }}</p>
                                <p class="text-lg font-semibold">{{ number_format($amount, 2) }}</p>
                                <p class="text-xs text-base-content/60">${{ number_format($resourceValues[$resource] ?? 0, 0) }} value</p>
                            </div>
                        @empty
                            <p class="text-base-content/70">No resources looted yet.</p>
                        @endforelse
                        <div class="p-3 rounded-xl bg-primary/10 border border-primary/30">
                            <p class="text-xs uppercase text-base-content/70">Resource value</p>
                            <p class="text-lg font-semibold text-primary">${{ number_format($totals['resources_value'] ?? 0, 0) }}</p>
                            <p class="text-xs text-base-content/60">At 24h avg prices</p>
                        </div>
                        <div class="p-3 rounded-xl bg-secondary/10 border border-secondary/30">
                            <p class="text-xs uppercase text-base-content/70">Money looted</p>
                            <p class="text-lg font-semibold text-secondary">${{ number_format($totals['money_looted'] ?? 0, 0) }}</p>
                            <p class="text-xs text-base-content/60">Hard cash wins wars</p>
                        </div>
                    </div>
                    <div class="bg-base-200/40 border border-base-300 rounded-xl p-4">
                        <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Loot mix</p>
                        <canvas id="resourceMixChart" class="mt-3"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            const lootTimeline = @json($lootTimeline);
            const infraTimeline = @json($infraTimeline);
            const attackTimeline = @json($attackTimeline);
            const resourceMix = @json($resourceMix);
            const topLoot = @json($topLoot);
            const topInfra = @json($topInfra);
            const topEfficiency = @json($topEfficiency);

            const lootCtx = document.getElementById('lootTimelineChart');
            if (lootCtx) {
                new Chart(lootCtx, {
                    type: 'line',
                    data: {
                        labels: lootTimeline.labels,
                        datasets: [
                            {
                                label: 'Loot value',
                                data: lootTimeline.values,
                                borderColor: '#facc15',
                                backgroundColor: 'rgba(250, 204, 21, 0.15)',
                                fill: true,
                                tension: 0.35,
                            },
                        ],
                    },
                    options: {
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                ticks: { callback: value => `$${Number(value).toLocaleString()}` },
                                grid: { color: 'rgba(0,0,0,0.05)' },
                            },
                        },
                    },
                });
            }

            const infraCtx = document.getElementById('infraTimelineChart');
            if (infraCtx) {
                new Chart(infraCtx, {
                    type: 'bar',
                    data: {
                        labels: infraTimeline.labels,
                        datasets: [
                            {
                                label: 'Infra value',
                                data: infraTimeline.values,
                                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                borderRadius: 8,
                            },
                        ],
                    },
                    options: {
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                ticks: { callback: value => `$${Number(value).toLocaleString()}` },
                            },
                        },
                    },
                });
            }

            const resourceCtx = document.getElementById('resourceMixChart');
            if (resourceCtx) {
                new Chart(resourceCtx, {
                    type: 'doughnut',
                    data: {
                        labels: resourceMix.labels,
                        datasets: [
                            {
                                data: resourceMix.values,
                                backgroundColor: ['#38bdf8', '#fbbf24', '#34d399', '#f472b6', '#a78bfa', '#fb7185', '#f97316', '#22d3ee', '#4ade80', '#60a5fa', '#facc15'],
                            },
                        ],
                    },
                    options: {
                        plugins: { legend: { position: 'bottom' } },
                        cutout: '60%',
                    },
                });
            }

            const attackCtx = document.getElementById('attackTimelineChart');
            if (attackCtx) {
                new Chart(attackCtx, {
                    type: 'line',
                    data: {
                        labels: attackTimeline.labels,
                        datasets: [
                            {
                                label: 'Attacks',
                                data: attackTimeline.values,
                                borderColor: '#60a5fa',
                                backgroundColor: 'rgba(96, 165, 250, 0.15)',
                                fill: true,
                                tension: 0.35,
                            },
                        ],
                    },
                    options: {
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { ticks: { callback: value => Number(value).toLocaleString() } },
                        },
                    },
                });
            }

            const topLootCtx = document.getElementById('topLootChart');
            if (topLootCtx) {
                new Chart(topLootCtx, {
                    type: 'bar',
                    data: {
                        labels: topLoot.labels,
                        datasets: [
                            {
                                data: topLoot.values,
                                backgroundColor: 'rgba(250, 204, 21, 0.7)',
                                borderRadius: 6,
                            },
                        ],
                    },
                    options: {
                        indexAxis: 'y',
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { ticks: { callback: value => `$${Number(value).toLocaleString()}` } },
                        },
                    },
                });
            }

            const topInfraCtx = document.getElementById('topInfraChart');
            if (topInfraCtx) {
                new Chart(topInfraCtx, {
                    type: 'bar',
                    data: {
                        labels: topInfra.labels,
                        datasets: [
                            {
                                data: topInfra.values,
                                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                borderRadius: 6,
                            },
                        ],
                    },
                    options: {
                        indexAxis: 'y',
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { ticks: { callback: value => `$${Number(value).toLocaleString()}` } },
                        },
                    },
                });
            }

            const topEfficiencyCtx = document.getElementById('topEfficiencyChart');
            if (topEfficiencyCtx) {
                new Chart(topEfficiencyCtx, {
                    type: 'bar',
                    data: {
                        labels: topEfficiency.labels,
                        datasets: [
                            {
                                data: topEfficiency.values,
                                backgroundColor: 'rgba(34, 197, 94, 0.7)',
                                borderRadius: 6,
                            },
                        ],
                    },
                    options: {
                        indexAxis: 'y',
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { ticks: { callback: value => `$${Number(value).toLocaleString()}` } },
                        },
                    },
                });
            }
        </script>
    @endpush
@endsection

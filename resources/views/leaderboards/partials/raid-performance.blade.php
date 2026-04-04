@php
    $filters = $activePayload['filters'] ?? ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()];
    $totals = $activePayload['totals'] ?? [];
    $leaderboards = $activePayload['leaderboards'] ?? [];
    $charts = $activePayload['charts'] ?? [];
    $selfStats = $activePayload['self_stats'] ?? null;

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
    $topLooter = $totals['top_looter'] ?? null;
    $topCloser = $totals['top_closer'] ?? null;
    $fromLabel = \Carbon\Carbon::parse($filters['from'])->format('M d, Y');
    $toLabel = \Carbon\Carbon::parse($filters['to'])->format('M d, Y');

    $lootTimeline = $charts['loot_timeline'] ?? ['labels' => [], 'values' => []];
    $infraTimeline = $charts['infra_timeline'] ?? ['labels' => [], 'values' => []];
    $attackTimeline = $charts['attack_timeline'] ?? ['labels' => [], 'values' => []];
    $resourceMix = $charts['resource_mix'] ?? ['labels' => [], 'values' => []];
    $topLoot = $charts['top_loot'] ?? ['labels' => [], 'values' => []];
    $topInfra = $charts['top_infra'] ?? ['labels' => [], 'values' => []];
    $topEfficiency = $charts['top_efficiency'] ?? ['labels' => [], 'values' => []];
@endphp

<div class="space-y-8">
    <section class="relative overflow-hidden rounded-[2rem] border border-base-300 bg-[radial-gradient(circle_at_top_left,_rgba(251,191,36,0.22),_transparent_30%),radial-gradient(circle_at_top_right,_rgba(239,68,68,0.16),_transparent_24%),linear-gradient(135deg,rgba(255,251,235,0.98),rgba(255,255,255,0.97),rgba(254,242,242,0.95))] shadow-2xl">
        <div class="relative grid gap-6 p-6 lg:grid-cols-[1.45fr,0.95fr] lg:p-8">
            <div class="space-y-5">
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.35em] text-amber-700">
                        <span>Leaderboard</span>
                        <span class="h-1 w-1 rounded-full bg-amber-500"></span>
                        <span>Raid Performance</span>
                    </div>
                    <h2 class="max-w-4xl text-3xl font-black tracking-tight text-slate-950 sm:text-5xl">
                        Raid Hall of Fame
                    </h2>
                    <p class="max-w-3xl text-sm leading-6 text-slate-700 sm:text-base">
                        Who is printing cash and wiping cities? This board ranks raiders by loot value, infra damage, and battlefield dominance.
                    </p>
                </div>

                <form class="grid gap-3 rounded-[1.5rem] border border-white/70 bg-white/85 p-4 shadow-sm sm:grid-cols-[1fr,1fr,auto,auto]" method="GET" action="{{ route('leaderboards.index', ['board' => 'raid-performance']) }}">
                    <div>
                        <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">From</label>
                        <input type="date" name="from" class="input input-bordered w-full" value="{{ $filters['from'] }}">
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">To</label>
                        <input type="date" name="to" class="input input-bordered w-full" value="{{ $filters['to'] }}">
                    </div>
                    <div class="flex items-end">
                        <button class="btn btn-primary w-full sm:w-auto" type="submit">Update</button>
                    </div>
                    <div class="flex items-end">
                        <a href="{{ route('leaderboards.index', ['board' => 'raid-performance']) }}" class="btn btn-outline w-full sm:w-auto">Reset</a>
                    </div>
                </form>
            </div>

            <div class="rounded-[1.5rem] border border-white/70 bg-slate-950 p-5 text-white shadow-xl">
                <div class="flex items-center justify-between text-xs font-semibold uppercase tracking-[0.24em] text-amber-300">
                    <span>Alliance totals</span>
                    <span>{{ $fromLabel }} - {{ $toLabel }}</span>
                </div>
                <p class="mt-4 text-4xl font-black">${{ number_format((float) ($totals['loot_value'] ?? 0), 0) }}</p>
                <p class="mt-2 text-sm text-slate-300">Money and resources looted at 24h average pricing.</p>
                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <p class="text-xs uppercase text-slate-300">Infra burned</p>
                        <p class="mt-2 text-2xl font-bold">${{ number_format((float) ($totals['infra_destroyed_value'] ?? 0), 0) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <p class="text-xs uppercase text-slate-300">Victories</p>
                        <p class="mt-2 text-2xl font-bold">{{ number_format((int) ($totals['victories'] ?? 0)) }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-[1.5rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Total loot value</p>
            <p class="mt-3 text-3xl font-black text-secondary">${{ number_format((float) ($totals['loot_value'] ?? 0), 0) }}</p>
            <p class="mt-2 text-xs text-base-content/60">Money + resources at 24h average prices.</p>
        </div>
        <div class="rounded-[1.5rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Loot per day</p>
            <p class="mt-3 text-3xl font-black text-primary">${{ number_format((float) ($totals['loot_value_per_day'] ?? 0), 0) }}</p>
            <p class="mt-2 text-xs text-base-content/60">Average daily haul across the selected window.</p>
        </div>
        <div class="rounded-[1.5rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Loot per victory</p>
            <p class="mt-3 text-3xl font-black text-accent">${{ number_format((float) ($totals['avg_loot_per_victory'] ?? 0), 0) }}</p>
            <p class="mt-2 text-xs text-base-content/60">Finisher reward for confirmed victory hits.</p>
        </div>
        <div class="rounded-[1.5rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Kill score total</p>
            <p class="mt-3 text-3xl font-black text-info">{{ number_format((float) ($totals['kills_score'] ?? 0), 2) }}</p>
            <p class="mt-2 text-xs text-base-content/60">Weighted military damage across all raid attacks.</p>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-3">
        <article class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-base-content/55">Top Looter</p>
            @if ($topLooter)
                <a href="https://politicsandwar.com/nation/id={{ $topLooter['id'] }}" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-2 text-2xl font-black text-base-content transition hover:text-primary">
                    <span>{{ $topLooter['nation_name'] }}</span>
                    <span class="text-sm opacity-50">-&gt;</span>
                </a>
                <p class="mt-1 text-sm text-base-content/60">{{ $topLooter['leader_name'] }}</p>
                <p class="mt-4 text-3xl font-black text-secondary">${{ number_format((float) ($topLooter['loot_value'] ?? 0), 0) }}</p>
                <p class="mt-2 text-xs text-base-content/55">{{ number_format((int) ($topLooter['victories'] ?? 0)) }} victories • {{ number_format((int) ($topLooter['attacks'] ?? 0)) }} attacks</p>
            @else
                <p class="mt-4 text-sm text-base-content/60">No raid data yet.</p>
            @endif
        </article>

        <article class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-base-content/55">Most Victories</p>
            @if ($topCloser)
                <a href="https://politicsandwar.com/nation/id={{ $topCloser['id'] }}" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-2 text-2xl font-black text-base-content transition hover:text-primary">
                    <span>{{ $topCloser['nation_name'] }}</span>
                    <span class="text-sm opacity-50">-&gt;</span>
                </a>
                <p class="mt-1 text-sm text-base-content/60">{{ $topCloser['leader_name'] }}</p>
                <p class="mt-4 text-3xl font-black text-primary">{{ number_format((int) ($topCloser['victories'] ?? 0)) }}</p>
                <p class="mt-2 text-xs text-base-content/55">${{ number_format((float) ($topCloser['loot_value'] ?? 0), 0) }} loot value</p>
            @else
                <p class="mt-4 text-sm text-base-content/60">No victory data yet.</p>
            @endif
        </article>

        <article class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-base-content/55">Raid Tempo</p>
            <p class="mt-3 text-3xl font-black text-accent">{{ number_format((int) ($totals['attacks'] ?? 0)) }}</p>
            <p class="mt-2 text-sm text-base-content/65">Total raid attacks recorded in this window.</p>
            <p class="mt-4 text-xl font-bold text-base-content">${{ number_format((float) ($totals['avg_loot_per_attack'] ?? 0), 0) }}</p>
            <p class="text-xs text-base-content/55">Average loot value per attack.</p>
        </article>
    </section>

    @if ($selfStats)
        @php
            $memberCount = $selfStats['member_count'] ?? 0;
            $lootRank = $selfStats['ranks']['loot_value'] ?? null;
            $victoryRank = $selfStats['ranks']['victories'] ?? null;
            $lootRateRank = $selfStats['ranks']['loot_per_attack'] ?? null;
            $killRateRank = $selfStats['ranks']['kill_score_per_attack'] ?? null;
            $lootPercentile = $lootRank && $memberCount > 0 ? round(((($memberCount - $lootRank) / $memberCount) * 100), 1) : 0;
        @endphp
        <section class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="grid gap-6 xl:grid-cols-3">
                <div class="space-y-3 xl:col-span-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Your raid performance</p>
                        <h3 class="mt-1 text-2xl font-black text-base-content">Personal Trophy Case</h3>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-secondary/30 bg-secondary/10 p-4">
                            <p class="text-xs uppercase text-base-content/70">Loot value</p>
                            <p class="mt-2 text-2xl font-bold text-secondary">${{ number_format((float) ($selfStats['stats']['loot_value'] ?? 0), 0) }}</p>
                            <p class="text-xs text-base-content/60">Rank #{{ $lootRank ?? '-' }} of {{ $memberCount }} • Top {{ $lootPercentile }}%</p>
                        </div>
                        <div class="rounded-2xl border border-primary/30 bg-primary/10 p-4">
                            <p class="text-xs uppercase text-base-content/70">Victories</p>
                            <p class="mt-2 text-2xl font-bold text-primary">{{ number_format((int) ($selfStats['stats']['victories'] ?? 0)) }}</p>
                            <p class="text-xs text-base-content/60">Rank #{{ $victoryRank ?? '-' }} • {{ number_format((int) ($selfStats['stats']['attacks'] ?? 0)) }} attacks</p>
                        </div>
                        <div class="rounded-2xl border border-accent/30 bg-accent/10 p-4">
                            <p class="text-xs uppercase text-base-content/70">Loot per attack</p>
                            <p class="mt-2 text-2xl font-bold text-accent">${{ number_format((float) ($selfStats['stats']['loot_per_attack'] ?? 0), 0) }}</p>
                            <p class="text-xs text-base-content/60">Rank #{{ $lootRateRank ?? '-' }}</p>
                        </div>
                        <div class="rounded-2xl border border-info/30 bg-info/10 p-4">
                            <p class="text-xs uppercase text-base-content/70">Kill score per attack</p>
                            <p class="mt-2 text-2xl font-bold text-info">{{ number_format((float) ($selfStats['stats']['kill_score_per_attack'] ?? 0), 2) }}</p>
                            <p class="text-xs text-base-content/60">Rank #{{ $killRateRank ?? '-' }}</p>
                        </div>
                    </div>
                </div>
                <div class="space-y-3">
                    <div class="rounded-2xl border border-base-300 bg-base-200/60 p-4">
                        <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Your share of loot</p>
                        <p class="mt-2 text-3xl font-bold text-secondary">{{ number_format((float) ($selfStats['loot_share'] ?? 0), 2) }}%</p>
                        <p class="text-xs text-base-content/60">Of alliance loot value.</p>
                    </div>
                    <div class="rounded-2xl border border-base-300 bg-base-200/60 p-4">
                        <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Infra damage</p>
                        <p class="mt-2 text-2xl font-bold text-primary">${{ number_format((float) ($selfStats['stats']['infra_destroyed_value'] ?? 0), 0) }}</p>
                        <p class="text-xs text-base-content/60">{{ number_format((float) ($selfStats['stats']['infra_destroyed'] ?? 0), 2) }} infra destroyed</p>
                    </div>
                    <div class="rounded-2xl border border-base-300 bg-base-200/60 p-4">
                        <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Unit score</p>
                        <p class="mt-2 text-2xl font-bold text-accent">{{ number_format((float) ($selfStats['stats']['unit_score'] ?? 0), 2) }}</p>
                        <p class="text-xs text-base-content/60">Weighted kills total.</p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Loot velocity</p>
                    <h3 class="text-xl font-black text-base-content">Daily loot value</h3>
                </div>
                <span class="badge badge-outline">24h avg pricing</span>
            </div>
            <canvas id="lootTimelineChart" class="mt-4"></canvas>
        </div>
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">City damage</p>
                    <h3 class="text-xl font-black text-base-content">Infra destroyed value</h3>
                </div>
                <span class="badge badge-outline">Daily totals</span>
            </div>
            <canvas id="infraTimelineChart" class="mt-4"></canvas>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Tempo</p>
                    <h3 class="text-xl font-black text-base-content">Daily attacks</h3>
                </div>
                <span class="badge badge-outline">All members</span>
            </div>
            <div class="h-64">
                <canvas id="attackTimelineChart" class="h-full"></canvas>
            </div>
        </div>
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Raid momentum</p>
                    <h3 class="text-xl font-black text-base-content">Peak performance</h3>
                </div>
                <span class="badge badge-outline">Highlights</span>
            </div>
            <div class="mt-4 grid gap-3">
                <div class="rounded-2xl border border-base-300 bg-base-200/60 p-4">
                    <p class="text-xs uppercase text-base-content/60">Best loot day</p>
                    <p class="mt-2 text-2xl font-bold text-secondary">
                        @if ($totals['best_loot_day'] ?? null)
                            ${{ number_format((float) $totals['best_loot_day']['value'], 0) }}
                        @else
                            $0
                        @endif
                    </p>
                    <p class="text-xs text-base-content/60">{{ $totals['best_loot_day']['label'] ?? 'No data yet' }}</p>
                </div>
                <div class="rounded-2xl border border-base-300 bg-base-200/60 p-4">
                    <p class="text-xs uppercase text-base-content/60">Best attack day</p>
                    <p class="mt-2 text-2xl font-bold text-primary">{{ number_format((int) ($totals['best_attack_day']['value'] ?? 0)) }} hits</p>
                    <p class="text-xs text-base-content/60">{{ $totals['best_attack_day']['label'] ?? 'No data yet' }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-secondary/30 bg-secondary/10 p-4">
                        <p class="text-xs uppercase text-base-content/70">Resource share</p>
                        <p class="mt-2 text-xl font-bold text-secondary">{{ number_format((float) ($totals['resource_share_pct'] ?? 0), 2) }}%</p>
                    </div>
                    <div class="rounded-2xl border border-primary/30 bg-primary/10 p-4">
                        <p class="text-xs uppercase text-base-content/70">Money share</p>
                        <p class="mt-2 text-xl font-bold text-primary">{{ number_format((float) ($totals['money_share_pct'] ?? 0), 2) }}%</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Loot leaderboard</p>
                    <h3 class="text-xl font-black text-base-content">Top Looters</h3>
                </div>
                <span class="badge badge-outline">Value</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($lootLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-secondary/15 font-bold text-secondary">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">${{ number_format((float) ($row['loot_value'] ?? 0), 0) }}</p>
                            <p class="text-xs text-base-content/55">{{ number_format((int) ($row['victories'] ?? 0)) }} victories</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No raid data yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Infra leaderboard</p>
                    <h3 class="text-xl font-black text-base-content">City Wreckers</h3>
                </div>
                <span class="badge badge-outline">Value</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($infraLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/15 font-bold text-primary">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">${{ number_format((float) ($row['infra_destroyed_value'] ?? 0), 0) }}</p>
                            <p class="text-xs text-base-content/55">{{ number_format((float) ($row['infra_destroyed'] ?? 0), 2) }} infra</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No infra damage yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Kill leaderboard</p>
                    <h3 class="text-xl font-black text-base-content">Unit Takers</h3>
                </div>
                <span class="badge badge-outline">Score</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($killLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-accent/15 font-bold text-accent">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">{{ number_format((float) ($row['unit_score'] ?? 0), 2) }} score</p>
                            <p class="text-xs text-base-content/55">{{ number_format((int) ($row['soldiers_killed'] ?? 0)) }}s • {{ number_format((int) ($row['tanks_killed'] ?? 0)) }}t • {{ number_format((int) ($row['aircraft_killed'] ?? 0)) }}a • {{ number_format((int) ($row['ships_killed'] ?? 0)) }}sh</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No kill data yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Victory leaderboard</p>
                    <h3 class="text-xl font-black text-base-content">Closers</h3>
                </div>
                <span class="badge badge-outline">Victories</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($victoryLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-info/15 font-bold text-info">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">{{ number_format((int) ($row['victories'] ?? 0)) }} wins</p>
                            <p class="text-xs text-base-content/55">${{ number_format((float) ($row['loot_value'] ?? 0), 0) }} loot value</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No victory data yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-3">
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Efficiency</p>
                    <h3 class="text-xl font-black text-base-content">Loot per Attack</h3>
                </div>
                <span class="badge badge-outline">Value</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($lootRateLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-secondary/15 font-bold text-secondary">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">${{ number_format((float) ($row['loot_per_attack'] ?? 0), 0) }}</p>
                            <p class="text-xs text-base-content/55">{{ number_format((int) ($row['attacks'] ?? 0)) }} attacks</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No efficiency data yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Closers</p>
                    <h3 class="text-xl font-black text-base-content">Loot per Victory</h3>
                </div>
                <span class="badge badge-outline">Value</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($lootCloserLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-accent/15 font-bold text-accent">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">${{ number_format((float) ($row['loot_per_victory'] ?? 0), 0) }}</p>
                            <p class="text-xs text-base-content/55">{{ number_format((int) ($row['victories'] ?? 0)) }} victories</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No finisher data yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Hitters</p>
                    <h3 class="text-xl font-black text-base-content">Most Attacks</h3>
                </div>
                <span class="badge badge-outline">Volume</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($attackLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-info/15 font-bold text-info">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">{{ number_format((int) ($row['attacks'] ?? 0)) }} attacks</p>
                            <p class="text-xs text-base-content/55">${{ number_format((float) ($row['loot_value'] ?? 0), 0) }} loot value</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No attack volume yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Pressure</p>
                    <h3 class="text-xl font-black text-base-content">Infra per Attack</h3>
                </div>
                <span class="badge badge-outline">Value</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($infraRateLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/15 font-bold text-primary">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">${{ number_format((float) ($row['infra_per_attack'] ?? 0), 0) }}</p>
                            <p class="text-xs text-base-content/55">{{ number_format((int) ($row['attacks'] ?? 0)) }} attacks</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No infra efficiency yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Kill rate</p>
                    <h3 class="text-xl font-black text-base-content">Kill Score per Attack</h3>
                </div>
                <span class="badge badge-outline">Score</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($killRateLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-accent/15 font-bold text-accent">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">{{ number_format((float) ($row['kill_score_per_attack'] ?? 0), 2) }}</p>
                            <p class="text-xs text-base-content/55">{{ number_format((int) ($row['attacks'] ?? 0)) }} attacks</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No kill efficiency yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Money bags</p>
                    <h3 class="text-xl font-black text-base-content">Cash Looted</h3>
                </div>
                <span class="badge badge-outline">Money</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($moneyLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-warning/15 font-bold text-warning">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-base-content">${{ number_format((float) ($row['money_looted'] ?? 0), 0) }}</p>
                            <p class="text-xs text-base-content/55">{{ number_format((int) ($row['victories'] ?? 0)) }} victories</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No cash loot yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Top charts</p>
                    <h3 class="text-xl font-black text-base-content">Leaderboard Spotlights</h3>
                </div>
                <span class="badge badge-outline">Top 6</span>
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
    </section>

    <section class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Loot breakdown</p>
                <h3 class="text-xl font-black text-base-content">Resources hauled</h3>
            </div>
            <span class="badge badge-ghost">{{ $fromLabel }} - {{ $toLabel }}</span>
        </div>
        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($resourceTotals as $resource => $amount)
                    <div class="rounded-xl border border-base-300 bg-base-200/60 p-3">
                        <p class="text-xs uppercase text-base-content/60">{{ $resource }}</p>
                        <p class="mt-2 text-lg font-semibold">{{ number_format((float) $amount, 2) }}</p>
                        <p class="text-xs text-base-content/60">${{ number_format((float) ($resourceValues[$resource] ?? 0), 0) }} value</p>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No resources looted yet.</p>
                @endforelse
                <div class="rounded-xl border border-primary/30 bg-primary/10 p-3">
                    <p class="text-xs uppercase text-base-content/70">Resource value</p>
                    <p class="mt-2 text-lg font-semibold text-primary">${{ number_format((float) ($totals['resources_value'] ?? 0), 0) }}</p>
                    <p class="text-xs text-base-content/60">At 24h average prices.</p>
                </div>
                <div class="rounded-xl border border-secondary/30 bg-secondary/10 p-3">
                    <p class="text-xs uppercase text-base-content/70">Money looted</p>
                    <p class="mt-2 text-lg font-semibold text-secondary">${{ number_format((float) ($totals['money_looted'] ?? 0), 0) }}</p>
                    <p class="text-xs text-base-content/60">Hard cash from victory hits.</p>
                </div>
            </div>
            <div class="rounded-xl border border-base-300 bg-base-200/40 p-4">
                <p class="text-xs uppercase tracking-[0.2em] text-base-content/60">Loot mix</p>
                <canvas id="resourceMixChart" class="mt-3"></canvas>
            </div>
        </div>
    </section>
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
                    datasets: [{
                        label: 'Loot value',
                        data: lootTimeline.values,
                        borderColor: '#facc15',
                        backgroundColor: 'rgba(250, 204, 21, 0.15)',
                        fill: true,
                        tension: 0.35,
                    }],
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
                    datasets: [{
                        label: 'Infra value',
                        data: infraTimeline.values,
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderRadius: 8,
                    }],
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

        const attackCtx = document.getElementById('attackTimelineChart');
        if (attackCtx) {
            new Chart(attackCtx, {
                type: 'line',
                data: {
                    labels: attackTimeline.labels,
                    datasets: [{
                        label: 'Attacks',
                        data: attackTimeline.values,
                        borderColor: '#60a5fa',
                        backgroundColor: 'rgba(96, 165, 250, 0.15)',
                        fill: true,
                        tension: 0.35,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            ticks: { callback: value => Number(value).toLocaleString() },
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
                    datasets: [{
                        data: resourceMix.values,
                        backgroundColor: ['#38bdf8', '#fbbf24', '#34d399', '#f472b6', '#a78bfa', '#fb7185', '#f97316', '#22d3ee', '#4ade80', '#60a5fa', '#facc15'],
                    }],
                },
                options: {
                    plugins: { legend: { position: 'bottom' } },
                    cutout: '60%',
                },
            });
        }

        const topLootCtx = document.getElementById('topLootChart');
        if (topLootCtx) {
            new Chart(topLootCtx, {
                type: 'bar',
                data: {
                    labels: topLoot.labels,
                    datasets: [{
                        data: topLoot.values,
                        backgroundColor: 'rgba(250, 204, 21, 0.7)',
                        borderRadius: 6,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: {
                            ticks: { callback: value => `$${Number(value).toLocaleString()}` },
                        },
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
                    datasets: [{
                        data: topInfra.values,
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderRadius: 6,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: {
                            ticks: { callback: value => `$${Number(value).toLocaleString()}` },
                        },
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
                    datasets: [{
                        data: topEfficiency.values,
                        backgroundColor: 'rgba(34, 197, 94, 0.7)',
                        borderRadius: 6,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: {
                            ticks: { callback: value => `$${Number(value).toLocaleString()}` },
                        },
                    },
                },
            });
        }
    </script>
@endpush

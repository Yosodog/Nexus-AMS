@php
    $filters = $activePayload['filters'] ?? ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()];
    $totals = $activePayload['totals'] ?? [];
    $lootLeaders = $activePayload['leaderboards']['loot'] ?? [];
    $victoryLeaders = $activePayload['leaderboards']['victories'] ?? [];
    $efficiencyLeaders = $activePayload['leaderboards']['loot_rate'] ?? [];
    $damageLeaders = $activePayload['leaderboards']['infra'] ?? [];
    $topLooter = $totals['top_looter'] ?? null;
    $topCloser = $totals['top_closer'] ?? null;
@endphp

<div class="space-y-8">
    <section class="relative overflow-hidden rounded-[2rem] border border-base-300 bg-[radial-gradient(circle_at_top_left,_rgba(251,191,36,0.22),_transparent_30%),radial-gradient(circle_at_top_right,_rgba(239,68,68,0.16),_transparent_24%),linear-gradient(135deg,rgba(255,251,235,0.98),rgba(255,255,255,0.97),rgba(254,242,242,0.95))] shadow-2xl">
        <div class="relative grid gap-6 p-6 lg:grid-cols-[1.5fr,0.9fr] lg:p-8">
            <div class="space-y-5">
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.35em] text-amber-700">
                        <span>Leaderboard</span>
                        <span class="h-1 w-1 rounded-full bg-amber-500"></span>
                        <span>Raid Performance</span>
                    </div>
                    <h2 class="max-w-4xl text-3xl font-black tracking-tight text-slate-950 sm:text-5xl">
                        Who is turning wars into real alliance value?
                    </h2>
                    <p class="max-w-3xl text-sm leading-6 text-slate-700 sm:text-base">
                        This board highlights the raiders bringing back the most loot, closing the most wars, and burning the most value over the selected window.
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

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                <div class="rounded-[1.5rem] border border-white/70 bg-slate-950 p-5 text-white shadow-xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-300">Loot Value</p>
                    <p class="mt-3 text-3xl font-black">${{ number_format((float) ($totals['loot_value'] ?? 0), 0) }}</p>
                    <p class="mt-2 text-xs text-slate-300">Total raid haul in the selected window.</p>
                </div>
                <div class="rounded-[1.5rem] border border-white/70 bg-white/85 p-5 shadow-lg">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Victories</p>
                    <p class="mt-3 text-3xl font-black text-slate-950">{{ number_format((int) ($totals['victories'] ?? 0)) }}</p>
                    <p class="mt-2 text-xs text-slate-500">Confirmed closing hits by alliance members.</p>
                </div>
                <div class="rounded-[1.5rem] border border-white/70 bg-white/85 p-5 shadow-lg">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Infra Burned</p>
                    <p class="mt-3 text-3xl font-black text-slate-950">${{ number_format((float) ($totals['infra_destroyed_value'] ?? 0), 0) }}</p>
                    <p class="mt-2 text-xs text-slate-500">Value destroyed across all recorded attacks.</p>
                </div>
            </div>
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

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Top 10</p>
                    <h3 class="text-xl font-black text-base-content">Loot value</h3>
                </div>
                <span class="badge badge-outline">Raid haul</span>
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
                        <p class="text-sm font-bold text-base-content">${{ number_format((float) ($row['loot_value'] ?? 0), 0) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No raid data yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Top 10</p>
                    <h3 class="text-xl font-black text-base-content">Loot per attack</h3>
                </div>
                <span class="badge badge-outline">Efficiency</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($efficiencyLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-500/15 font-bold text-amber-700">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <p class="text-sm font-bold text-base-content">${{ number_format((float) ($row['loot_per_attack'] ?? 0), 0) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No efficiency data yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Top 10</p>
                    <h3 class="text-xl font-black text-base-content">Victories</h3>
                </div>
                <span class="badge badge-outline">Closers</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($victoryLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/15 font-bold text-primary">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <p class="text-sm font-bold text-base-content">{{ number_format((int) ($row['victories'] ?? 0)) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No victory data yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-base-content/55">Top 10</p>
                    <h3 class="text-xl font-black text-base-content">Infra damage</h3>
                </div>
                <span class="badge badge-outline">Pressure</span>
            </div>
            <div class="mt-5 space-y-2">
                @forelse ($damageLeaders as $row)
                    <div class="flex items-center justify-between rounded-2xl border border-base-300 bg-base-200/40 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-error/15 font-bold text-error">{{ $row['rank'] }}</span>
                            <div>
                                <a href="https://politicsandwar.com/nation/id={{ $row['id'] }}" target="_blank" rel="noopener" class="font-semibold text-base-content transition hover:text-primary">{{ $row['leader_name'] }}</a>
                                <p class="text-xs text-base-content/55">{{ $row['nation_name'] }}</p>
                            </div>
                        </div>
                        <p class="text-sm font-bold text-base-content">${{ number_format((float) ($row['infra_destroyed_value'] ?? 0), 0) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No infra damage data yet.</p>
                @endforelse
            </div>
        </div>
    </section>
</div>

@php
    $rows = $activePayload['rows'] ?? [];
    $topNation = $rows[0] ?? null;
    $runnerUp = $rows[1] ?? null;
    $thirdPlace = $rows[2] ?? null;
    $positiveCount = collect($rows)->filter(fn ($row) => ($row['converted_profit_per_day'] ?? 0) >= 0)->count();
    $negativeCount = max(count($rows) - $positiveCount, 0);
    $generatedAt = filled($activePayload['generated_at'] ?? null)
        ? \Illuminate\Support\Carbon::parse($activePayload['generated_at'])->toDayDateTimeString()
        : 'N/A';
    $radiationSnapshotAt = filled($activePayload['radiation_snapshot_at'] ?? null)
        ? \Illuminate\Support\Carbon::parse($activePayload['radiation_snapshot_at'])->toDayDateTimeString()
        : 'Unavailable';
@endphp

<section class="space-y-8">
    <div class="space-y-8">
        <section class="overflow-hidden rounded-[2rem] border border-emerald-200/70 bg-[linear-gradient(140deg,rgba(255,255,255,0.98),rgba(236,253,245,0.96),rgba(255,251,235,0.92))] shadow-xl shadow-emerald-100/60">
            <div class="grid gap-6 p-6 lg:grid-cols-[1.3fr,0.95fr] lg:p-8">
                <div class="space-y-5">
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.32em] text-emerald-700">
                            <span>{{ $activeBoard['eyebrow'] }}</span>
                            <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                            <span>Live Snapshot</span>
                        </div>
                        <div class="space-y-2">
                            <h2 class="text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">{{ $activeBoard['title'] }}</h2>
                            <p class="max-w-3xl text-sm leading-6 text-slate-700 sm:text-base">
                                This board ranks eligible alliance members by current net profit per day, blending city income, manufacturing, power, food, military upkeep, prices, and radiation into one clean daily number.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-[1.4rem] border border-white/80 bg-white/85 p-4 shadow-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">Ranked Nations</p>
                            <p class="mt-2 text-2xl font-black text-slate-950">{{ number_format(count($rows)) }}</p>
                        </div>
                        <div class="rounded-[1.4rem] border border-emerald-200 bg-emerald-50/80 p-4 shadow-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-700">Positive Net</p>
                            <p class="mt-2 text-2xl font-black text-emerald-900">{{ number_format($positiveCount) }}</p>
                        </div>
                        <div class="rounded-[1.4rem] border border-rose-200 bg-rose-50/75 p-4 shadow-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-rose-700">Under Water</p>
                            <p class="mt-2 text-2xl font-black text-rose-900">{{ number_format($negativeCount) }}</p>
                        </div>
                    </div>

                    @if ($topNation)
                        <div class="rounded-[1.6rem] border border-slate-950 bg-slate-950 p-5 text-white shadow-xl">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="space-y-2">
                                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-300">Top Performer</p>
                                    <div class="space-y-1">
                                        <a href="{{ $topNation['nation_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-2xl font-black tracking-tight text-white transition hover:text-emerald-300">
                                            <span>{{ $topNation['nation_name'] }}</span>
                                            <span class="text-sm font-semibold text-white/40">-&gt;</span>
                                        </a>
                                        <p class="text-sm text-slate-300">{{ $topNation['leader_name'] }} • {{ number_format($topNation['cities']) }} cities</p>
                                    </div>
                                </div>
                                <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-right shadow-sm">
                                    <p class="text-xs uppercase tracking-[0.25em] text-emerald-300">Net / Day</p>
                                    <p class="mt-1 text-3xl font-black">${{ number_format($topNation['converted_profit_per_day'], 2) }}</p>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="space-y-4">
                    <div class="rounded-[1.6rem] border border-white/80 bg-white/90 p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Price Basis</p>
                                <p class="mt-2 text-lg font-black text-slate-950">{{ $activePayload['price_basis'] ?? '24h average trade prices' }}</p>
                            </div>
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-800">Current</span>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                        <div class="rounded-[1.6rem] border border-slate-200 bg-white/85 p-5 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Generated</p>
                            <p class="mt-3 text-lg font-black text-slate-900">{{ $generatedAt }}</p>
                            <p class="mt-2 text-xs text-slate-500">Last refresh time for the current ranking.</p>
                        </div>

                        <div class="rounded-[1.6rem] border border-slate-200 bg-white/85 p-5 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Radiation Snapshot</p>
                            <p class="mt-3 text-lg font-black text-slate-900">{{ $radiationSnapshotAt }}</p>
                            <p class="mt-2 text-xs text-slate-500">Most recent world radiation reading used in the ranking.</p>
                        </div>
                    </div>

                    <div class="rounded-[1.6rem] border border-slate-950 bg-slate-950 p-5 text-white shadow-xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">Read It Fast</p>
                        <div class="mt-3 space-y-2 text-sm text-slate-200">
                            <p>Net profit per day matters more than raw city count.</p>
                            <p>Applicants and Vacation Mode nations are excluded from the board.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-[2rem] border border-base-300 bg-base-100 shadow-xl">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-base-300 bg-gradient-to-r from-base-100 via-base-100 to-base-200/60 px-6 py-5">
                <div>
                    <h3 class="text-xl font-bold text-base-content">Profitability ranking</h3>
                    <p class="mt-1 text-sm text-base-content/70">
                        Applicants and Vacation Mode nations are excluded. Values below are current daily net profit, not stockpile totals.
                    </p>
                </div>
                <div class="rounded-2xl border border-base-300 bg-base-100 px-4 py-3 text-right shadow-sm">
                    <p class="text-xs uppercase tracking-[0.24em] text-base-content/55">Leaderboard scope</p>
                    <p class="mt-1 text-sm font-semibold text-base-content">Alliance members only</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead class="bg-base-200/70 text-xs uppercase tracking-[0.22em] text-base-content/60">
                    <tr>
                        <th>#</th>
                        <th>Leader</th>
                        <th>Nation</th>
                        <th>Cities</th>
                        <th class="text-right">Net / day</th>
                        <th class="text-right">Money</th>
                        <th class="text-right">Manufacturing</th>
                        <th>Breakdown</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($rows as $row)
                        @php
                            $resources = $row['resource_profit_per_day'] ?? [];
                            $manufacturing = ($resources['gasoline'] ?? 0) + ($resources['munitions'] ?? 0) + ($resources['steel'] ?? 0) + ($resources['aluminum'] ?? 0);
                            $rowClasses = match ((int) ($row['rank'] ?? 0)) {
                                1 => 'bg-emerald-50/70',
                                2 => 'bg-amber-50/55',
                                3 => 'bg-orange-50/55',
                                default => '',
                            };
                        @endphp
                        <tr class="{{ $rowClasses }} hover">
                            <td>
                                <span class="inline-flex min-w-12 justify-center rounded-full border border-base-300 bg-base-100 px-3 py-1 text-xs font-bold shadow-sm">
                                    #{{ $row['rank'] }}
                                </span>
                            </td>
                            <td>
                                <div class="space-y-1">
                                    <p class="font-semibold text-base-content">{{ $row['leader_name'] }}</p>
                                    <p class="text-xs text-base-content/55">Nation owner</p>
                                </div>
                            </td>
                            <td>
                                <a href="{{ $row['nation_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 font-semibold text-primary transition hover:text-secondary">
                                    <span>{{ $row['nation_name'] }}</span>
                                    <span class="text-xs opacity-60">-&gt;</span>
                                </a>
                            </td>
                            <td>{{ number_format($row['cities']) }}</td>
                            <td class="text-right font-black {{ $row['converted_profit_per_day'] >= 0 ? 'text-success' : 'text-error' }}">
                                ${{ number_format($row['converted_profit_per_day'], 2) }}
                            </td>
                            <td class="text-right {{ $row['money_profit_per_day'] >= 0 ? 'text-success' : 'text-error' }}">
                                {{ number_format($row['money_profit_per_day'], 2) }}
                            </td>
                            <td class="text-right {{ $manufacturing >= 0 ? 'text-success' : 'text-error' }}">
                                {{ number_format($manufacturing, 2) }}
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-2 text-xs">
                                    <span class="badge border-base-300 bg-base-100">Food {{ number_format($resources['food'] ?? 0, 2) }}</span>
                                    <span class="badge border-base-300 bg-base-100">Steel {{ number_format($resources['steel'] ?? 0, 2) }}</span>
                                    <span class="badge border-base-300 bg-base-100">Gas {{ number_format($resources['gasoline'] ?? 0, 2) }}</span>
                                    <span class="badge border-base-300 bg-base-100">Muni {{ number_format($resources['munitions'] ?? 0, 2) }}</span>
                                    <span class="badge border-base-300 bg-base-100">Alu {{ number_format($resources['aluminum'] ?? 0, 2) }}</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-16 text-center">
                                <div class="mx-auto max-w-xl space-y-3">
                                    <p class="text-lg font-bold text-base-content">No profitability snapshots available yet.</p>
                                    <p class="text-sm text-base-content/60">
                                        Check back after profitability data has been refreshed for alliance members.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[1.4fr,0.95fr]">
            @if ($topNation || $runnerUp || $thirdPlace)
                <div class="grid gap-4 md:grid-cols-3">
                    @foreach ([$topNation, $runnerUp, $thirdPlace] as $spotlight)
                        @continue(! $spotlight)

                        @php
                            $resources = $spotlight['resource_profit_per_day'] ?? [];
                            $spotlightManufacturing = ($resources['gasoline'] ?? 0) + ($resources['munitions'] ?? 0) + ($resources['steel'] ?? 0) + ($resources['aluminum'] ?? 0);
                        @endphp

                        <article class="rounded-[1.75rem] border border-base-300 bg-base-100 p-5 shadow-md transition hover:-translate-y-1 hover:shadow-xl">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-base-content/55">Rank #{{ $spotlight['rank'] }}</p>
                                    <a href="{{ $spotlight['nation_url'] }}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-2 text-xl font-bold text-base-content transition hover:text-primary">
                                        <span>{{ $spotlight['nation_name'] }}</span>
                                        <span class="text-sm opacity-50">-&gt;</span>
                                    </a>
                                    <p class="mt-1 text-sm text-base-content/65">{{ $spotlight['leader_name'] }}</p>
                                </div>
                                <span class="badge badge-outline whitespace-nowrap">{{ number_format($spotlight['cities']) }} cities</span>
                            </div>
                            <div class="mt-5 grid gap-3">
                                <div class="rounded-2xl bg-emerald-50 p-4">
                                    <p class="text-xs uppercase tracking-[0.22em] text-emerald-700">Converted</p>
                                    <p class="mt-2 text-2xl font-black text-emerald-900">${{ number_format($spotlight['converted_profit_per_day'], 2) }}</p>
                                </div>
                                <div class="rounded-2xl bg-amber-50 p-4">
                                    <p class="text-xs uppercase tracking-[0.22em] text-amber-700">Manufacturing</p>
                                    <p class="mt-2 text-2xl font-black text-amber-900">{{ number_format($spotlightManufacturing, 2) }}</p>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            <section class="rounded-[2rem] border border-base-300 bg-base-100 p-6 shadow-lg">
                <div class="space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-base-content/55">Coach's Notes</p>
                    <h3 class="text-2xl font-black text-base-content">Use the board to spot who is compounding and who is quietly leaking value.</h3>
                    <p class="text-sm leading-6 text-base-content/70">
                        Start with net profit, then compare the money line and manufacturing line. A strong net with weak manufacturing usually means city income is carrying the nation. A weak net with strong city count usually means the build, military load, or power mix needs work.
                    </p>
                </div>
            </section>
        </section>
    </div>
</section>

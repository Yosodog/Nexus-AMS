@extends('layouts.main')

@section('content')
    @php
        $rows = $profitability['rows'] ?? [];
        $topNation = $rows[0] ?? null;
        $runnerUp = $rows[1] ?? null;
        $thirdPlace = $rows[2] ?? null;
        $positiveCount = collect($rows)->filter(fn ($row) => ($row['converted_profit_per_day'] ?? 0) >= 0)->count();
    @endphp

    <div class="mx-auto max-w-7xl space-y-8">
        <section class="relative overflow-hidden rounded-[2rem] border border-base-300 bg-[radial-gradient(circle_at_top_left,_rgba(16,185,129,0.2),_transparent_30%),radial-gradient(circle_at_top_right,_rgba(251,191,36,0.18),_transparent_28%),linear-gradient(135deg,rgba(255,255,255,0.98),rgba(240,253,244,0.95),rgba(255,251,235,0.96))] shadow-2xl">
            <div class="absolute inset-y-0 right-0 hidden w-1/3 bg-[linear-gradient(180deg,transparent,rgba(15,23,42,0.04),transparent)] lg:block"></div>
            <div class="relative grid gap-6 p-6 lg:grid-cols-[1.6fr,0.9fr] lg:p-8">
                <div class="space-y-5">
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.35em] text-emerald-700">
                            <span>Leaderboards</span>
                            <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                            <span>Alliance Economics</span>
                        </div>
                        <h1 class="max-w-4xl text-3xl font-black tracking-tight text-slate-900 sm:text-5xl">
                            Daily nation profitability, ranked and ready to scan.
                        </h1>
                        <p class="max-w-3xl text-sm leading-6 text-slate-700 sm:text-base">
                            Current net economic output per day across eligible alliance members, converted with 24-hour average trade prices and the latest stored radiation snapshot.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3 text-sm">
                        <span class="badge border-emerald-300 bg-emerald-50 px-3 py-3 text-emerald-800">{{ count($rows) }} ranked nations</span>
                        <span class="badge border-amber-300 bg-amber-50 px-3 py-3 text-amber-800">{{ $positiveCount }} profitable</span>
                        <span class="badge border-slate-300 bg-white/80 px-3 py-3 text-slate-700">{{ $profitability['price_basis'] ?? '24h average trade prices' }}</span>
                    </div>

                    @if ($topNation)
                        <div class="rounded-[1.75rem] border border-emerald-200 bg-white/85 p-5 shadow-lg shadow-emerald-100/60 backdrop-blur">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="space-y-2">
                                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-700">Top performer</p>
                                    <div class="space-y-1">
                                        <a href="{{ $topNation['nation_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-2xl font-black tracking-tight text-slate-900 transition hover:text-emerald-700">
                                            <span>{{ $topNation['nation_name'] }}</span>
                                            <span class="text-sm font-semibold text-slate-400">-&gt;</span>
                                        </a>
                                        <p class="text-sm text-slate-600">{{ $topNation['leader_name'] }} | {{ number_format($topNation['cities']) }} cities</p>
                                    </div>
                                </div>
                                <div class="rounded-2xl bg-slate-950 px-5 py-4 text-right text-white shadow-xl">
                                    <p class="text-xs uppercase tracking-[0.25em] text-emerald-300">Net / day</p>
                                    <p class="mt-1 text-3xl font-black">${{ number_format($topNation['converted_profit_per_day'], 2) }}</p>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                    <div class="rounded-[1.6rem] border border-slate-200 bg-white/80 p-5 shadow-lg shadow-slate-200/50 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Generated</p>
                        <p class="mt-3 text-lg font-black text-slate-900">{{ optional($profitability['generated_at'] ?? null)?->toDayDateTimeString() ?? 'N/A' }}</p>
                        <p class="mt-2 text-xs text-slate-500">Last refresh time for the current ranking.</p>
                    </div>

                    <div class="rounded-[1.6rem] border border-slate-200 bg-white/80 p-5 shadow-lg shadow-slate-200/50 backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Radiation Snapshot</p>
                        <p class="mt-3 text-lg font-black text-slate-900">{{ optional($profitability['radiation_snapshot_at'] ?? null)?->toDayDateTimeString() ?? 'Unavailable' }}</p>
                        <p class="mt-2 text-xs text-slate-500">Most recent world radiation reading used in the ranking.</p>
                    </div>

                    <div class="rounded-[1.6rem] border border-slate-200 bg-slate-950 p-5 text-white shadow-xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">What This Shows</p>
                        <div class="mt-3 space-y-2 text-sm text-slate-200">
                            <p>Net profit per day, not stockpile totals.</p>
                            <p>Applicants and Vacation Mode nations are excluded.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        @if ($topNation || $runnerUp || $thirdPlace)
            <section class="grid gap-4 lg:grid-cols-3">
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
                            <span class="badge badge-outline">{{ number_format($spotlight['cities']) }} cities</span>
                        </div>
                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
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
            </section>
        @endif

        <section class="overflow-hidden rounded-[2rem] border border-base-300 bg-base-100 shadow-xl">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-base-300 bg-gradient-to-r from-base-100 via-base-100 to-base-200/60 px-6 py-5">
                <div>
                    <h2 class="text-xl font-bold text-base-content">Profitability ranking</h2>
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
                        @endphp
                        <tr class="hover">
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
    </div>
@endsection

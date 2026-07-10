@php
    $rows = $activePayload['rows'] ?? [];
    $topNation = $rows[0] ?? null;
    $positiveCount = collect($rows)->filter(fn ($row) => ($row['converted_profit_per_day'] ?? 0) >= 0)->count();
    $negativeCount = max(count($rows) - $positiveCount, 0);
    $generatedAt = filled($activePayload['generated_at'] ?? null)
        ? \Illuminate\Support\Carbon::parse($activePayload['generated_at'])->toDayDateTimeString()
        : 'Not available';
    $radiationSnapshotAt = filled($activePayload['radiation_snapshot_at'] ?? null)
        ? \Illuminate\Support\Carbon::parse($activePayload['radiation_snapshot_at'])->toDayDateTimeString()
        : 'Not available';
@endphp

<div class="nexus-stack">
    <section class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]" aria-labelledby="profitability-summary-title">
        <div class="nexus-panel">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="profitability-summary-title" class="nexus-section-title">Current profitability picture</h2>
                    <p class="nexus-body-muted mt-1">Estimated daily net after income, production, power, and military upkeep.</p>
                </div>
            </div>

            <dl class="grid gap-px bg-base-300 sm:grid-cols-3">
                <div class="bg-base-100 p-5">
                    <dt class="nexus-stat-label">Ranked nations</dt>
                    <dd class="nexus-stat-value mt-2">{{ number_format(count($rows)) }}</dd>
                    <p class="nexus-stat-helper">Eligible alliance members</p>
                </div>
                <div class="bg-base-100 p-5">
                    <dt class="nexus-stat-label">Positive daily net</dt>
                    <dd class="nexus-stat-value mt-2 text-success">{{ number_format($positiveCount) }}</dd>
                    <p class="nexus-stat-helper">At or above break-even</p>
                </div>
                <div class="bg-base-100 p-5">
                    <dt class="nexus-stat-label">Below break-even</dt>
                    <dd class="nexus-stat-value mt-2 {{ $negativeCount > 0 ? 'text-error' : '' }}">{{ number_format($negativeCount) }}</dd>
                    <p class="nexus-stat-helper">Candidates for build review</p>
                </div>
            </dl>

            @if($topNation)
                <div class="grid gap-4 border-t border-base-300 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                    <div class="min-w-0">
                        <p class="nexus-kicker">Current leader</p>
                        <a href="{{ $topNation['nation_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 block truncate text-xl font-bold text-primary hover:underline">
                            {{ $topNation['nation_name'] }}
                        </a>
                        <p class="mt-1 text-sm text-base-content/60">{{ $topNation['leader_name'] }} · {{ number_format($topNation['cities']) }} cities</p>
                    </div>
                    <div class="sm:text-right">
                        <p class="nexus-stat-label">Net per day</p>
                        <p class="mt-2 font-display text-2xl font-bold tabular-nums text-success">${{ number_format($topNation['converted_profit_per_day'], 2) }}</p>
                    </div>
                </div>
            @endif
        </div>

        <aside class="nexus-panel" aria-labelledby="profitability-context-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="profitability-context-title" class="nexus-section-title">Calculation context</h2>
                    <p class="nexus-body-muted mt-1">Freshness and market basis for this estimate.</p>
                </div>
            </div>
            <dl class="divide-y divide-base-300">
                <div class="px-5 py-4">
                    <dt class="nexus-stat-label">Price basis</dt>
                    <dd class="mt-1 text-sm font-semibold text-base-content">{{ $activePayload['price_basis'] ?? '24h average trade prices' }}</dd>
                </div>
                <div class="px-5 py-4">
                    <dt class="nexus-stat-label">Ranking generated</dt>
                    <dd class="mt-1 text-sm font-semibold text-base-content">{{ $generatedAt }}</dd>
                </div>
                <div class="px-5 py-4">
                    <dt class="nexus-stat-label">Radiation snapshot</dt>
                    <dd class="mt-1 text-sm font-semibold text-base-content">{{ $radiationSnapshotAt }}</dd>
                </div>
            </dl>
        </aside>
    </section>

    <section class="nexus-panel overflow-hidden" aria-labelledby="profitability-ranking-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="profitability-ranking-title" class="nexus-section-title">Profitability ranking</h2>
                <p class="nexus-body-muted mt-1">Applicants and Vacation Mode nations are excluded. Figures are daily flow estimates, not current stockpiles.</p>
            </div>
            <span class="nexus-status nexus-status--neutral">Alliance members</span>
        </div>

        @if(empty($rows))
            <div class="nexus-empty-state">
                <x-icon name="o-chart-bar" class="size-7 text-base-content/40" aria-hidden="true" />
                <div>
                    <p class="font-semibold">No profitability snapshots available</p>
                    <p class="mt-1 text-sm text-base-content/60">The ranking will appear after profitability data is refreshed.</p>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="nexus-table" data-sortable="true">
                    <thead>
                        <tr>
                            <th scope="col" data-sortable="false">Rank</th>
                            <th scope="col">Leader</th>
                            <th scope="col">Nation</th>
                            <th scope="col" class="text-right">Cities</th>
                            <th scope="col" class="text-right">Net / day</th>
                            <th scope="col" class="text-right">Cash</th>
                            <th scope="col" class="text-right">Manufacturing</th>
                            <th scope="col" data-sortable="false">Resource detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php
                                $resources = $row['resource_profit_per_day'] ?? [];
                                $manufacturing = ($resources['gasoline'] ?? 0)
                                    + ($resources['munitions'] ?? 0)
                                    + ($resources['steel'] ?? 0)
                                    + ($resources['aluminum'] ?? 0);
                            @endphp
                            <tr>
                                <td><span class="nexus-status nexus-status--neutral">#{{ $row['rank'] }}</span></td>
                                <td>
                                    <span class="block font-semibold">{{ $row['leader_name'] }}</span>
                                    <span class="block text-xs text-base-content/55">Nation owner</span>
                                </td>
                                <td>
                                    <a href="{{ $row['nation_url'] }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-primary hover:underline">{{ $row['nation_name'] }}</a>
                                </td>
                                <td class="text-right tabular-nums" data-order="{{ (int) $row['cities'] }}">{{ number_format($row['cities']) }}</td>
                                <td class="text-right font-semibold tabular-nums {{ $row['converted_profit_per_day'] >= 0 ? 'text-success' : 'text-error' }}" data-order="{{ (float) $row['converted_profit_per_day'] }}">
                                    ${{ number_format($row['converted_profit_per_day'], 2) }}
                                </td>
                                <td class="text-right tabular-nums {{ $row['money_profit_per_day'] >= 0 ? 'text-success' : 'text-error' }}" data-order="{{ (float) $row['money_profit_per_day'] }}">
                                    {{ number_format($row['money_profit_per_day'], 2) }}
                                </td>
                                <td class="text-right tabular-nums {{ $manufacturing >= 0 ? 'text-success' : 'text-error' }}" data-order="{{ (float) $manufacturing }}">
                                    {{ number_format($manufacturing, 2) }}
                                </td>
                                <td>
                                    <div class="flex min-w-72 flex-wrap gap-1.5 text-xs">
                                        <span class="badge badge-ghost">Food {{ number_format($resources['food'] ?? 0, 2) }}</span>
                                        <span class="badge badge-ghost">Steel {{ number_format($resources['steel'] ?? 0, 2) }}</span>
                                        <span class="badge badge-ghost">Gas {{ number_format($resources['gasoline'] ?? 0, 2) }}</span>
                                        <span class="badge badge-ghost">Muni {{ number_format($resources['munitions'] ?? 0, 2) }}</span>
                                        <span class="badge badge-ghost">Alu {{ number_format($resources['aluminum'] ?? 0, 2) }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <aside class="border-l-4 border-info bg-info/10 px-5 py-4 text-sm leading-6 text-base-content" aria-label="How to interpret profitability">
        <p class="font-semibold">Read net profit first, then compare cash and manufacturing.</p>
        <p class="mt-1 text-base-content/70">A high city count with weak net output can point to an inefficient build, heavy military upkeep, or an unfavorable power mix.</p>
    </aside>
</div>

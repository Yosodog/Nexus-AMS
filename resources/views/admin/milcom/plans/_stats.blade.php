@php
    $statsSummary = data_get($stats, 'summary', []);
    $forces = data_get($stats, 'forces', []);
    $sideResults = data_get($stats, 'side_results', []);
    $attention = data_get($stats, 'attention', []);
    $charts = data_get($stats, 'charts', []);
    $currentWars = collect(data_get($stats, 'current_wars', []));
    $allianceStats = collect(data_get($stats, 'alliances', []));
    $contributors = collect(data_get($stats, 'contributors', []));
    $waitingRows = collect(data_get($attention, 'waiting_to_declare_rows', []));
    $attentionCount = (int) data_get($attention, 'waiting_to_declare', 0)
        + (int) data_get($attention, 'no_first_hit', 0)
        + (int) data_get($attention, 'low_resistance', 0);
    $hasChartActivity = array_sum(data_get($charts, 'outgoing_attacks', []))
        + array_sum(data_get($charts, 'incoming_attacks', [])) > 0;
    $formatMoney = static function ($value): string {
        $amount = (float) $value;

        return ($amount < 0 ? '-$' : '$').number_format(abs($amount), 0);
    };
    $formatDate = static function ($value): string {
        if (blank($value)) {
            return 'No activity recorded';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->diffForHumans();
        } catch (\Throwable) {
            return (string) $value;
        }
    };
    $formatCompactNumber = static function ($value): string {
        $number = (float) $value;
        $absolute = abs($number);

        foreach ([1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'K'] as $divisor => $suffix) {
            if ($absolute >= $divisor) {
                $scaled = $number / $divisor;
                $precision = abs($scaled) >= 100 ? 0 : 1;

                return rtrim(rtrim(number_format($scaled, $precision), '0'), '.').$suffix;
            }
        }

        return number_format($number, $number === floor($number) ? 0 : 1);
    };
    $formatCompactMoney = static function ($value) use ($formatCompactNumber): string {
        $amount = (float) $value;

        return ($amount < 0 ? '-$' : '$').$formatCompactNumber(abs($amount));
    };
@endphp

<section class="nexus-panel" aria-labelledby="operation-stats-title">
    <div class="nexus-panel__header items-start">
        <div>
            <h2 id="operation-stats-title" class="nexus-section-title">Operation stats</h2>
            <p class="mt-1 max-w-3xl text-sm text-base-content/65">Only declarations matched to this wave are counted. Use this view to spot slow starts, weak wars, and alliance-level trends.</p>
        </div>
        <a href="{{ route('admin.milcom.plans.show', ['operation' => $operationId, 'stage' => 'stats']) }}" class="btn btn-ghost btn-sm">
            <x-icon name="o-arrow-path" class="size-4" aria-hidden="true" />
            Refresh
        </a>
    </div>

    <dl class="nexus-metrics rounded-none border-x-0 border-b-0">
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Declarations</dt>
            <dd class="nexus-stat-value">{{ number_format((float) data_get($statsSummary, 'declaration_rate', 0), 1) }}%</dd>
            <dd class="nexus-stat-helper">{{ number_format((int) data_get($statsSummary, 'declared_assignments', 0)) }} of {{ number_format((int) data_get($statsSummary, 'assignments', 0)) }} assignments</dd>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Current wars</dt>
            <dd class="nexus-stat-value">{{ number_format((int) data_get($statsSummary, 'active_wars', 0)) }}</dd>
            <dd class="nexus-stat-helper">{{ number_format((int) data_get($statsSummary, 'finished_wars', 0)) }} finished</dd>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Results</dt>
            <dd class="nexus-stat-value">{{ number_format((int) data_get($statsSummary, 'wins', 0)) }}–{{ number_format((int) data_get($statsSummary, 'losses', 0)) }}</dd>
            <dd class="nexus-stat-helper">Wins and losses · {{ number_format((int) data_get($statsSummary, 'no_result', 0)) }} without a result</dd>
        </div>
        <div class="nexus-metric {{ (float) data_get($statsSummary, 'net_infra_value', 0) < 0 ? 'bg-error/5' : 'bg-success/5' }}">
            <dt class="nexus-stat-label">Net infra damage</dt>
            <dd class="nexus-stat-value {{ (float) data_get($statsSummary, 'net_infra_value', 0) < 0 ? 'text-error' : 'text-success' }}">{{ $formatMoney(data_get($statsSummary, 'net_infra_value', 0)) }}</dd>
            <dd class="nexus-stat-helper">Inflicted minus taken</dd>
        </div>
        <div class="nexus-metric">
            <dt class="nexus-stat-label">Money looted</dt>
            <dd class="nexus-stat-value text-success">{{ $formatMoney(data_get($statsSummary, 'loot', 0)) }}</dd>
            <dd class="nexus-stat-helper">Across matched declarations</dd>
        </div>
    </dl>
</section>

<section class="nexus-panel" aria-labelledby="side-comparison-title">
    <div class="nexus-panel__header items-start">
        <div>
            <h2 id="side-comparison-title" class="nexus-section-title">Side comparison</h2>
            <p class="mt-1 max-w-3xl text-sm text-base-content/65">Compare the nations in this wave, then see what each side has done in matched wars.</p>
        </div>
        <div class="text-right text-xs text-base-content/55">
            <div>{{ data_get($forces, 'source') === 'latest_generation' ? 'Roster from the latest team generation' : 'Roster from this wave' }}</div>
            <div>Nation data updated {{ $formatDate(data_get($forces, 'as_of')) }}</div>
        </div>
    </div>

    <div class="grid divide-y divide-base-300 xl:grid-cols-2 xl:divide-x xl:divide-y-0">
        <div class="min-w-0">
            <div class="border-b border-base-300 px-4 py-3">
                <h3 class="font-semibold">Current forces</h3>
                <p class="mt-1 text-xs text-base-content/60">Totals use the latest stored P&amp;W data. Per-city figures make differently sized sides easier to compare.</p>
                <p class="mt-1 text-xs text-base-content/50">Military data: {{ number_format((int) data_get($forces, 'friendly.military_reports', 0)) }} of {{ number_format((int) data_get($forces, 'friendly.nations', 0)) }} friendly nations · {{ number_format((int) data_get($forces, 'enemy.military_reports', 0)) }} of {{ number_format((int) data_get($forces, 'enemy.nations', 0)) }} target nations</p>
            </div>
            <div class="grid grid-cols-[minmax(0,1fr)_minmax(5.75rem,auto)_minmax(0,1fr)] items-center gap-3 border-b border-base-300 bg-base-200/45 px-4 py-2 text-xs font-semibold sm:gap-5">
                <div class="text-right text-success">Your side</div>
                <div class="text-center text-base-content/50">Measure</div>
                <div class="text-error">Target side</div>
            </div>
            <dl class="divide-y divide-base-300">
                @foreach ([
                    ['Nations', 'nations', null, null],
                    ['Cities', 'cities', 'average_cities', 'per nation'],
                    ['Score', 'score', 'average_score', 'avg. per nation'],
                    ['Soldiers', 'soldiers', 'soldiers_per_city', 'per city'],
                    ['Tanks', 'tanks', 'tanks_per_city', 'per city'],
                    ['Aircraft', 'aircraft', 'aircraft_per_city', 'per city'],
                    ['Ships', 'ships', 'ships_per_city', 'per city'],
                    ['Missiles', 'missiles', null, null],
                    ['Nukes', 'nukes', null, null],
                    ['Spies', 'spies', null, null],
                ] as [$label, $key, $detailKey, $detailLabel])
                    @php
                        $friendlyValue = data_get($forces, 'friendly.'.$key, 0);
                        $enemyValue = data_get($forces, 'enemy.'.$key, 0);
                    @endphp
                    <x-milcom.stat-comparison-row
                        :label="$label"
                        :friendly-value="$formatCompactNumber($friendlyValue)"
                        :enemy-value="$formatCompactNumber($enemyValue)"
                        :friendly-title="number_format((float) $friendlyValue, $key === 'score' ? 2 : 0)"
                        :enemy-title="number_format((float) $enemyValue, $key === 'score' ? 2 : 0)"
                        :friendly-raw="$friendlyValue"
                        :enemy-raw="$enemyValue"
                        :friendly-detail="$detailKey ? $formatCompactNumber(data_get($forces, 'friendly.'.$detailKey, 0)).' '.$detailLabel : null"
                        :enemy-detail="$detailKey ? $formatCompactNumber(data_get($forces, 'enemy.'.$detailKey, 0)).' '.$detailLabel : null"
                    />
                @endforeach
                <x-milcom.stat-comparison-row
                    label="Active wars"
                    :friendly-value="$formatCompactNumber((int) data_get($forces, 'friendly.offensive_wars', 0) + (int) data_get($forces, 'friendly.defensive_wars', 0))"
                    :enemy-value="$formatCompactNumber((int) data_get($forces, 'enemy.offensive_wars', 0) + (int) data_get($forces, 'enemy.defensive_wars', 0))"
                    :friendly-raw="(int) data_get($forces, 'friendly.offensive_wars', 0) + (int) data_get($forces, 'friendly.defensive_wars', 0)"
                    :enemy-raw="(int) data_get($forces, 'enemy.offensive_wars', 0) + (int) data_get($forces, 'enemy.defensive_wars', 0)"
                    :friendly-detail="number_format((int) data_get($forces, 'friendly.offensive_wars', 0)).' offensive · '.number_format((int) data_get($forces, 'friendly.defensive_wars', 0)).' defensive'"
                    :enemy-detail="number_format((int) data_get($forces, 'enemy.offensive_wars', 0)).' offensive · '.number_format((int) data_get($forces, 'enemy.defensive_wars', 0)).' defensive'"
                />
            </dl>
        </div>

        <div class="min-w-0">
            <div class="border-b border-base-300 px-4 py-3">
                <h3 class="font-semibold">Battle results</h3>
                <p class="mt-1 text-xs text-base-content/60">Only attacks and declarations linked to this wave count here.</p>
            </div>
            <div class="grid grid-cols-[minmax(0,1fr)_minmax(5.75rem,auto)_minmax(0,1fr)] items-center gap-3 border-b border-base-300 bg-base-200/45 px-4 py-2 text-xs font-semibold sm:gap-5">
                <div class="text-right text-success">Your side</div>
                <div class="text-center text-base-content/50">Result</div>
                <div class="text-error">Target side</div>
            </div>
            <dl class="divide-y divide-base-300">
                <x-milcom.stat-comparison-row
                    label="Wars won"
                    :friendly-value="$formatCompactNumber(data_get($sideResults, 'friendly.wars_won', 0))"
                    :enemy-value="$formatCompactNumber(data_get($sideResults, 'enemy.wars_won', 0))"
                    :friendly-raw="data_get($sideResults, 'friendly.wars_won', 0)"
                    :enemy-raw="data_get($sideResults, 'enemy.wars_won', 0)"
                />
                <x-milcom.stat-comparison-row
                    label="Successful attacks"
                    :friendly-value="$formatCompactNumber(data_get($sideResults, 'friendly.successful_attacks', 0))"
                    :enemy-value="$formatCompactNumber(data_get($sideResults, 'enemy.successful_attacks', 0))"
                    :friendly-raw="data_get($sideResults, 'friendly.successful_attacks', 0)"
                    :enemy-raw="data_get($sideResults, 'enemy.successful_attacks', 0)"
                    :friendly-detail="number_format((float) data_get($sideResults, 'friendly.attack_success_rate', 0), 1).'% of attacks'"
                    :enemy-detail="number_format((float) data_get($sideResults, 'enemy.attack_success_rate', 0), 1).'% of attacks'"
                />
                <x-milcom.stat-comparison-row
                    label="Infra destroyed"
                    :friendly-value="$formatCompactNumber(data_get($sideResults, 'friendly.infra_destroyed', 0))"
                    :enemy-value="$formatCompactNumber(data_get($sideResults, 'enemy.infra_destroyed', 0))"
                    :friendly-raw="data_get($sideResults, 'friendly.infra_destroyed', 0)"
                    :enemy-raw="data_get($sideResults, 'enemy.infra_destroyed', 0)"
                    :friendly-detail="$formatCompactMoney(data_get($sideResults, 'friendly.infra_destroyed_value', 0)).' value'"
                    :enemy-detail="$formatCompactMoney(data_get($sideResults, 'enemy.infra_destroyed_value', 0)).' value'"
                />
                @foreach ([
                    ['Soldiers destroyed', 'soldiers_destroyed'],
                    ['Tanks destroyed', 'tanks_destroyed'],
                    ['Aircraft destroyed', 'aircraft_destroyed'],
                    ['Ships destroyed', 'ships_destroyed'],
                    ['Missiles used', 'missiles_used'],
                    ['Nukes used', 'nukes_used'],
                ] as [$label, $key])
                    @php
                        $friendlyValue = data_get($sideResults, 'friendly.'.$key, 0);
                        $enemyValue = data_get($sideResults, 'enemy.'.$key, 0);
                    @endphp
                    <x-milcom.stat-comparison-row
                        :label="$label"
                        :friendly-value="$formatCompactNumber($friendlyValue)"
                        :enemy-value="$formatCompactNumber($enemyValue)"
                        :friendly-title="number_format((float) $friendlyValue)"
                        :enemy-title="number_format((float) $enemyValue)"
                        :friendly-raw="$friendlyValue"
                        :enemy-raw="$enemyValue"
                    />
                @endforeach
                <x-milcom.stat-comparison-row
                    label="Money looted"
                    :friendly-value="$formatCompactMoney(data_get($sideResults, 'friendly.loot', 0))"
                    :enemy-value="$formatCompactMoney(data_get($sideResults, 'enemy.loot', 0))"
                    :friendly-raw="data_get($sideResults, 'friendly.loot', 0)"
                    :enemy-raw="data_get($sideResults, 'enemy.loot', 0)"
                    :friendly-title="$formatMoney(data_get($sideResults, 'friendly.loot', 0))"
                    :enemy-title="$formatMoney(data_get($sideResults, 'enemy.loot', 0))"
                />
            </dl>
        </div>
    </div>
</section>

@if ($attentionCount === 0)
    <div class="alert alert-success items-start" role="status">
        <x-icon name="o-check-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <div>
            <div class="font-semibold">Nothing needs attention right now</div>
            <p class="text-sm">Every matched active war has an outgoing attack, and no friendly is at low resistance.</p>
        </div>
    </div>
@else
    <section class="nexus-panel" aria-labelledby="stats-attention-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="stats-attention-title" class="nexus-section-title">Needs attention</h2>
                <p class="mt-1 text-sm text-base-content/65">Start with these. They are the most likely to need an officer follow-up.</p>
            </div>
            <span class="nexus-status nexus-status--warning">{{ number_format($attentionCount) }} flags</span>
        </div>
        <dl class="grid divide-y divide-base-300 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            <div class="p-4">
                <dt class="text-sm font-semibold">Still needs to declare</dt>
                <dd class="mt-1 text-2xl font-bold tabular-nums {{ (int) data_get($attention, 'waiting_to_declare', 0) > 0 ? 'text-warning' : 'text-success' }}">{{ number_format((int) data_get($attention, 'waiting_to_declare', 0)) }}</dd>
            </div>
            <div class="p-4">
                <dt class="text-sm font-semibold">No first hit recorded</dt>
                <dd class="mt-1 text-2xl font-bold tabular-nums {{ (int) data_get($attention, 'no_first_hit', 0) > 0 ? 'text-warning' : 'text-success' }}">{{ number_format((int) data_get($attention, 'no_first_hit', 0)) }}</dd>
            </div>
            <div class="p-4">
                <dt class="text-sm font-semibold">Low friendly resistance</dt>
                <dd class="mt-1 text-2xl font-bold tabular-nums {{ (int) data_get($attention, 'low_resistance', 0) > 0 ? 'text-error' : 'text-success' }}">{{ number_format((int) data_get($attention, 'low_resistance', 0)) }}</dd>
            </div>
        </dl>

        @if ($waitingRows->isNotEmpty())
            <div class="border-t border-base-300">
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Assigned nation</th>
                                <th>Target</th>
                                <th>Declare by</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($waitingRows as $row)
                                <tr>
                                    <td>
                                        <x-pw-nation-link :nation-id="data_get($row, 'friendly.id')" :label="data_get($row, 'friendly.nation_name', 'Unknown nation')" class="font-semibold" />
                                        <div class="text-xs text-base-content/60"><x-pw-nation-link :nation-id="data_get($row, 'friendly.id')" :label="data_get($row, 'friendly.leader_name', 'Unknown leader')" /></div>
                                    </td>
                                    <td>
                                        <x-pw-nation-link :nation-id="data_get($row, 'target.id')" :label="data_get($row, 'target.nation_name', 'Unknown nation')" class="font-semibold" />
                                        <div class="text-xs text-base-content/60"><x-pw-nation-link :nation-id="data_get($row, 'target.id')" :label="data_get($row, 'target.leader_name', 'Unknown leader')" /></div>
                                    </td>
                                    <td class="whitespace-nowrap text-sm">{{ $formatDate(data_get($row, 'deadline_at')) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ((int) data_get($attention, 'waiting_to_declare', 0) > $waitingRows->count())
                    <p class="border-t border-base-300 px-4 py-3 text-xs text-base-content/60">Showing the {{ number_format($waitingRows->count()) }} most urgent assignments.</p>
                @endif
            </div>
        @endif
    </section>
@endif

<div class="grid gap-6 xl:grid-cols-2">
    <section class="nexus-panel" aria-labelledby="combat-activity-chart-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="combat-activity-chart-title" class="nexus-section-title">Combat activity</h2>
                <p class="mt-1 text-sm text-base-content/65">Recorded attacks during the last 14 days.</p>
            </div>
            <span class="badge badge-ghost">{{ number_format((int) data_get($statsSummary, 'outgoing_attacks', 0)) }} outgoing</span>
        </div>
        <div class="h-72 p-4">
            @if ($hasChartActivity)
                <canvas id="milcom-activity-chart" role="img" aria-label="Daily outgoing and incoming attacks for this operation"></canvas>
            @else
                <div class="grid h-full place-items-center text-center text-sm text-base-content/60">
                    <div>
                        <x-icon name="o-chart-bar" class="mx-auto size-9 text-base-content/30" aria-hidden="true" />
                        <p class="mt-2 font-semibold text-base-content/75">No attacks recorded yet</p>
                        <p>The chart will fill in as assigned wars begin.</p>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <section class="nexus-panel" aria-labelledby="damage-chart-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="damage-chart-title" class="nexus-section-title">Infrastructure damage</h2>
                <p class="mt-1 text-sm text-base-content/65">Dollar value recorded by attacks during the last 14 days.</p>
            </div>
            <span class="badge badge-ghost">{{ number_format((float) data_get($statsSummary, 'attack_success_rate', 0), 1) }}% successful</span>
        </div>
        <div class="h-72 p-4">
            @if ($hasChartActivity)
                <canvas id="milcom-damage-chart" role="img" aria-label="Daily infrastructure damage inflicted and taken for this operation"></canvas>
            @else
                <div class="grid h-full place-items-center text-center text-sm text-base-content/60">
                    <div>
                        <x-icon name="o-chart-bar" class="mx-auto size-9 text-base-content/30" aria-hidden="true" />
                        <p class="mt-2 font-semibold text-base-content/75">No damage recorded yet</p>
                        <p>Matched war attacks will appear here.</p>
                    </div>
                </div>
            @endif
        </div>
    </section>
</div>

<section class="nexus-panel" aria-labelledby="current-wars-title">
    <div class="nexus-panel__header">
        <div>
            <h2 id="current-wars-title" class="nexus-section-title">Current wars</h2>
            <p class="mt-1 text-sm text-base-content/65">Low resistance and missing first hits are shown first.</p>
        </div>
        <span class="badge badge-ghost">{{ number_format((int) data_get($stats, 'current_wars_total', 0)) }} active</span>
    </div>

    @if ($currentWars->isEmpty())
        <div class="p-8 text-center">
            <x-icon name="o-shield-check" class="mx-auto size-10 text-base-content/30" aria-hidden="true" />
            <p class="mt-3 font-semibold">No active wars are linked to this wave</p>
            <p class="mt-1 text-sm text-base-content/60">Wars will appear as assigned nations declare on their targets.</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Matchup</th>
                        <th>Status</th>
                        <th class="text-right">Attacks</th>
                        <th class="text-right">Resistance</th>
                        <th class="text-right">Infra exchange</th>
                        <th class="text-right">Turns</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($currentWars as $war)
                        <tr>
                            <td class="min-w-64">
                                <div class="flex items-center gap-2">
                                    <x-pw-nation-link :nation-id="data_get($war, 'friendly.id')" :label="data_get($war, 'friendly.nation_name', 'Unknown nation')" class="font-semibold" />
                                    <span class="text-base-content/35" aria-hidden="true">→</span>
                                    <x-pw-nation-link :nation-id="data_get($war, 'target.id')" :label="data_get($war, 'target.nation_name', 'Unknown nation')" class="font-semibold" />
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-1 text-xs text-base-content/60">
                                    <x-pw-nation-link :nation-id="data_get($war, 'friendly.id')" :label="data_get($war, 'friendly.leader_name', 'Unknown leader')" />
                                    <span aria-hidden="true">vs.</span>
                                    <x-pw-nation-link :nation-id="data_get($war, 'target.id')" :label="data_get($war, 'target.leader_name', 'Unknown leader')" />
                                    <span aria-hidden="true">·</span>
                                    <a href="{{ data_get($war, 'war_url') }}" target="_blank" rel="noopener noreferrer" class="link">War #{{ number_format((int) data_get($war, 'war_id')) }}</a>
                                </div>
                            </td>
                            <td>
                                @if (data_get($war, 'alert'))
                                    <span class="nexus-status {{ str_contains((string) data_get($war, 'alert'), 'resistance') ? 'nexus-status--error' : 'nexus-status--warning' }}">{{ data_get($war, 'alert') }}</span>
                                @else
                                    <span class="nexus-status nexus-status--success">On track</span>
                                @endif
                                <div class="mt-1 text-xs text-base-content/55">Last attack: {{ $formatDate(data_get($war, 'last_attack_at')) }}</div>
                            </td>
                            <td class="text-right tabular-nums">
                                <span class="font-semibold text-success">{{ number_format((int) data_get($war, 'outgoing_attacks', 0)) }}</span>
                                <span class="text-base-content/40">/</span>
                                <span class="text-error">{{ number_format((int) data_get($war, 'incoming_attacks', 0)) }}</span>
                            </td>
                            <td class="text-right tabular-nums">
                                <span class="font-semibold {{ (int) data_get($war, 'friendly_resistance', 0) <= 25 ? 'text-error' : '' }}">{{ number_format((int) data_get($war, 'friendly_resistance', 0)) }}</span>
                                <span class="text-base-content/40">/</span>
                                <span>{{ number_format((int) data_get($war, 'target_resistance', 0)) }}</span>
                            </td>
                            <td class="text-right text-sm tabular-nums">
                                <div class="text-success">+{{ $formatMoney(data_get($war, 'infra_inflicted_value', 0)) }}</div>
                                <div class="text-error">−{{ $formatMoney(data_get($war, 'infra_taken_value', 0)) }}</div>
                            </td>
                            <td class="text-right font-semibold tabular-nums">{{ number_format((int) data_get($war, 'turns_left', 0)) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ((int) data_get($stats, 'current_wars_total', 0) > $currentWars->count())
            <p class="border-t border-base-300 px-4 py-3 text-xs text-base-content/60">Showing the {{ number_format($currentWars->count()) }} wars that need attention soonest.</p>
        @endif
    @endif
</section>

<section class="nexus-panel" aria-labelledby="alliance-performance-title">
        <div class="nexus-panel__header">
            <div>
                <h2 id="alliance-performance-title" class="nexus-section-title">Alliance performance</h2>
                <p class="mt-1 text-sm text-base-content/65">A side-by-side view of the alliances involved in matched wars.</p>
            </div>
        </div>
        @if ($allianceStats->isEmpty())
            <p class="p-6 text-sm text-base-content/60">Alliance stats will appear after the first matched declaration.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Alliance</th>
                            <th>Side</th>
                            <th class="text-right">Wars</th>
                            <th class="text-right">W–L</th>
                            <th class="text-right">Net infra</th>
                            <th class="text-right">Loot</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($allianceStats as $row)
                            @php $alliance = data_get($row, 'alliance', []); @endphp
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        @if (data_get($alliance, 'flag'))
                                            <img src="{{ data_get($alliance, 'flag') }}" alt="" class="h-6 w-9 rounded-sm object-cover ring-1 ring-base-300" loading="lazy">
                                        @endif
                                        @if (data_get($alliance, 'url'))
                                            <a href="{{ data_get($alliance, 'url') }}" target="_blank" rel="noopener noreferrer" class="link font-semibold">{{ data_get($alliance, 'name', 'Unknown alliance') }}</a>
                                        @else
                                            <span class="font-semibold">{{ data_get($alliance, 'name', 'No alliance') }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td><span class="badge {{ data_get($row, 'side') === 'friendly' ? 'badge-success badge-soft' : 'badge-error badge-soft' }}">{{ data_get($row, 'side') === 'friendly' ? 'Your side' : 'Target side' }}</span></td>
                                <td class="text-right tabular-nums">{{ number_format((int) data_get($row, 'wars', 0)) }} <span class="text-xs text-base-content/50">({{ number_format((int) data_get($row, 'active_wars', 0)) }} live)</span></td>
                                <td class="text-right tabular-nums">{{ number_format((int) data_get($row, 'wins', 0)) }}–{{ number_format((int) data_get($row, 'losses', 0)) }}</td>
                                <td class="text-right font-semibold tabular-nums {{ (float) data_get($row, 'net_infra_value', 0) < 0 ? 'text-error' : 'text-success' }}">{{ $formatMoney(data_get($row, 'net_infra_value', 0)) }}</td>
                                <td class="text-right tabular-nums">{{ $formatMoney(data_get($row, 'loot', 0)) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
</section>

<section class="nexus-panel" aria-labelledby="top-contributors-title">
    <div class="nexus-panel__header">
        <div>
            <h2 id="top-contributors-title" class="nexus-section-title">Top contributors</h2>
            <p class="mt-1 text-sm text-base-content/65">Ranked by infrastructure damage inflicted in matched wars.</p>
        </div>
    </div>
    @if ($contributors->isEmpty())
        <p class="p-6 text-sm text-base-content/60">Contributor stats will appear after the first matched declaration.</p>
    @else
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Nation</th>
                        <th>Alliance</th>
                        <th class="text-right">Wars</th>
                        <th class="text-right">Wins</th>
                        <th class="text-right">Infra damage</th>
                        <th class="text-right">Loot</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($contributors as $row)
                        @php
                            $nation = data_get($row, 'nation', []);
                            $alliance = data_get($nation, 'alliance', []);
                        @endphp
                        <tr>
                            <td>
                                <x-pw-nation-link :nation-id="data_get($nation, 'id')" :label="data_get($nation, 'nation_name', 'Unknown nation')" class="font-semibold" />
                                <div class="text-xs text-base-content/60"><x-pw-nation-link :nation-id="data_get($nation, 'id')" :label="data_get($nation, 'leader_name', 'Unknown leader')" /></div>
                            </td>
                            <td>
                                @if (data_get($alliance, 'url'))
                                    <a href="{{ data_get($alliance, 'url') }}" target="_blank" rel="noopener noreferrer" class="link">{{ data_get($alliance, 'name', 'Unknown alliance') }}</a>
                                @else
                                    {{ data_get($alliance, 'name', 'No alliance') }}
                                @endif
                            </td>
                            <td class="text-right tabular-nums">{{ number_format((int) data_get($row, 'wars', 0)) }} <span class="text-xs text-base-content/50">({{ number_format((int) data_get($row, 'active_wars', 0)) }} live)</span></td>
                            <td class="text-right tabular-nums">{{ number_format((int) data_get($row, 'wins', 0)) }}</td>
                            <td class="text-right font-semibold text-success tabular-nums">{{ $formatMoney(data_get($row, 'infra_inflicted_value', 0)) }}</td>
                            <td class="text-right tabular-nums">{{ $formatMoney(data_get($row, 'loot', 0)) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="text-right text-xs text-base-content/50">Updated {{ $formatDate(data_get($stats, 'generated_at')) }}</p>

@if ($hasChartActivity)
    <table class="sr-only">
        <caption>Daily operation activity used by the charts</caption>
        <thead><tr><th>Day</th><th>Outgoing attacks</th><th>Incoming attacks</th><th>Infra damage inflicted</th><th>Infra damage taken</th></tr></thead>
        <tbody>
            @foreach (data_get($charts, 'labels', []) as $index => $label)
                <tr>
                    <th>{{ $label }}</th>
                    <td>{{ data_get($charts, 'outgoing_attacks.'.$index, 0) }}</td>
                    <td>{{ data_get($charts, 'incoming_attacks.'.$index, 0) }}</td>
                    <td>{{ data_get($charts, 'infra_inflicted_value.'.$index, 0) }}</td>
                    <td>{{ data_get($charts, 'infra_taken_value.'.$index, 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@pushOnce('scripts')
    <x-chart-js />
    <script>
        (() => {
            const chartData = {{ Js::from($charts) }};

            if (typeof window.Chart === 'undefined' || !window.NexusCharts) {
                return;
            }

            const palette = window.NexusCharts.colors();
            const sharedOptions = {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true },
                },
            };
            const activityCanvas = document.getElementById('milcom-activity-chart');

            if (activityCanvas) {
                new Chart(activityCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartData.labels,
                        datasets: [
                            {
                                label: 'Outgoing attacks',
                                nexusColor: 'success',
                                backgroundColor: palette.success,
                                data: chartData.outgoing_attacks,
                                borderRadius: 3,
                            },
                            {
                                label: 'Incoming attacks',
                                nexusColor: 'error',
                                backgroundColor: palette.error,
                                data: chartData.incoming_attacks,
                                borderRadius: 3,
                            },
                        ],
                    },
                    options: sharedOptions,
                });
            }

            const damageCanvas = document.getElementById('milcom-damage-chart');

            if (damageCanvas) {
                new Chart(damageCanvas, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: [
                            {
                                label: 'Inflicted',
                                nexusColor: 'success',
                                borderColor: palette.success,
                                backgroundColor: palette.success,
                                data: chartData.infra_inflicted_value,
                                borderWidth: 2,
                                pointRadius: 2,
                                tension: 0.25,
                            },
                            {
                                label: 'Taken',
                                nexusColor: 'error',
                                borderColor: palette.error,
                                backgroundColor: palette.error,
                                data: chartData.infra_taken_value,
                                borderWidth: 2,
                                pointRadius: 2,
                                tension: 0.25,
                            },
                        ],
                    },
                    options: {
                        ...sharedOptions,
                        plugins: {
                            ...sharedOptions.plugins,
                            tooltip: {
                                callbacks: {
                                    label: (context) => `${context.dataset.label}: $${Number(context.raw).toLocaleString()}`,
                                },
                            },
                        },
                        scales: {
                            ...sharedOptions.scales,
                            y: {
                                ...sharedOptions.scales.y,
                                ticks: {
                                    callback: (value) => `$${Number(value).toLocaleString()}`,
                                },
                            },
                        },
                    },
                });
            }
        })();
    </script>
@endPushOnce

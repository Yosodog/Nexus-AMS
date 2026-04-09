@extends('layouts.admin')

@section('content')
    @php
        $aggressor = $counter->aggressor;
        $aggressorMilitary = $aggressor?->military;
        $aggressorLastActive = $aggressor?->accountProfile?->last_active;
        $resolvedWarReason = old('war_reason', $counter->war_reason ?: ($defaultWarReason ?? 'Counter'));
        $counterCosting = $counterCosting ?? [];
        $counterCostSummary = $counterCosting['summary'] ?? [];
        $counterCostWars = $counterCosting['wars'] ?? collect();
        $counterCostParticipants = $counterCosting['participants'] ?? collect();
        $counterRecentReimbursements = $counterCosting['recent_reimbursements'] ?? collect();
        $tradePriceAsOf = $counterCosting['trade_price_as_of'] ?? null;
        $canManageAccounts = $canManageAccounts ?? false;
        $activeReimbursementNationId = (int) old('nation_id', 0);
    @endphp
    <x-header title="Counter" separator>
        <x-slot:subtitle>
            @if($counter->aggressor)
                <a href="https://politicsandwar.com/nation/id={{ $counter->aggressor->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover font-semibold">
                    {{ $counter->aggressor->leader_name }}
                </a>
            @else
                Unknown Aggressor
            @endif
            <span class="mx-2 text-base-content/40">•</span>
            <span class="badge badge-primary badge-sm uppercase">{{ $counter->status }}</span>
        </x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('admin.war-room') }}" class="btn btn-outline btn-sm">Back to War Room</a>
        </x-slot:actions>
    </x-header>

    <div class="space-y-6">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            <div class="space-y-6">
                <x-card title="Counter Overview">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div class="font-medium text-base-content/70">Team Size</div>
                        <div class="text-right"><span class="badge badge-info">{{ $counter->team_size }}</span></div>

                        <div class="font-medium text-base-content/70">Aggressor Alliance</div>
                        <div class="text-right">
                            @if($counter->aggressor?->alliance)
                                <a href="https://politicsandwar.com/alliance/id={{ $counter->aggressor->alliance->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover">
                                    {{ $counter->aggressor->alliance->name }}
                                </a>
                            @else
                                No Alliance
                            @endif
                        </div>

                        <div class="font-medium text-base-content/70">Last War Declared</div>
                        <div class="text-right">{{ optional($counter->last_war_declared_at)->diffForHumans() ?? '—' }}</div>

                        <div class="font-medium text-base-content/70">Last Activity</div>
                        <div class="text-right">{{ $aggressorLastActive?->diffForHumans() ?? 'Unknown' }}</div>

                        <div class="font-medium text-base-content/70">Created</div>
                        <div class="text-right">{{ $counter->created_at->diffForHumans() }}</div>

                        <div class="font-medium text-base-content/70">War Type</div>
                        <div class="text-right uppercase">
                            {{ config('war.war_types')[strtolower($counter->war_declaration_type ?? '')] ?? ucfirst($counter->war_declaration_type ?? 'Unknown') }}
                        </div>

                        <div class="font-medium text-base-content/70">Discord Forum</div>
                        <div class="text-right">{{ $counter->discord_forum_channel_id ?: 'Default' }}</div>

                        <div class="font-medium text-base-content/70">Reason</div>
                        <div class="text-right">{{ $counter->war_reason ?: ($defaultWarReason ?? 'Counter') }}</div>
                    </div>

                    <div class="mt-4 rounded-box border border-base-300 bg-base-200/50 p-4 text-sm text-base-content/70">
                        <div>Aggressor score {{ number_format($aggressor->score ?? 0, 2) }} • Cities {{ $aggressor->num_cities ?? 0 }}</div>
                        <div>Last active {{ $aggressorLastActive?->format('M j, Y g:i A') ?? 'Unknown' }}</div>
                        <div>Soldiers {{ number_format(optional($aggressorMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($aggressorMilitary)->tanks ?? 0) }}</div>
                        <div>Aircraft {{ number_format(optional($aggressorMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($aggressorMilitary)->ships ?? 0) }}</div>
                    </div>

                    <div class="mt-6 space-y-4 border-t border-base-300 pt-4">
                        <form method="post" action="{{ route('admin.war-counters.update', $counter) }}" class="space-y-4">
                            @csrf
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block space-y-2">
                                    <span class="text-sm font-medium">War Type</span>
                                    <select class="select select-bordered select-sm w-full" name="war_declaration_type">
                                        @foreach (config('war.war_types') as $value => $label)
                                            <option value="{{ $value }}" @selected(old('war_declaration_type', $counter->war_declaration_type) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="block space-y-2">
                                    <span class="text-sm font-medium">Team Size</span>
                                    <input type="number" class="input input-bordered input-sm w-full" name="team_size" min="1" max="10" value="{{ old('team_size', $counter->team_size) }}">
                                </label>
                            </div>

                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Discord forum override</span>
                                <input type="text"
                                       class="input input-bordered input-sm w-full"
                                       name="discord_forum_channel_id"
                                       placeholder="Use default from War Room settings"
                                       value="{{ old('discord_forum_channel_id', $counter->discord_forum_channel_id) }}">
                            </label>

                            <label class="block space-y-2">
                                <span class="text-sm font-medium">War Reason</span>
                                <input type="text"
                                       class="input input-bordered input-sm w-full"
                                       name="war_reason"
                                       maxlength="255"
                                       placeholder="e.g. Counter"
                                       value="{{ $resolvedWarReason }}">
                            </label>

                            <button class="btn btn-outline btn-primary btn-sm w-full" type="submit">Save Counter Settings</button>
                        </form>

                        <form method="post" action="{{ route('admin.war-counters.auto-pick', $counter) }}">
                            @csrf
                            <button class="btn btn-outline btn-sm w-full" type="submit">Auto-Pick Assignments</button>
                        </form>

                        <form method="post" action="{{ route('admin.war-counters.assignments.manual', $counter) }}" class="space-y-3">
                            @csrf
                            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_7rem]">
                                <label class="block space-y-2">
                                    <span class="text-sm font-medium">Manually Add Nation (ID)</span>
                                    <input type="number" class="input input-bordered input-sm w-full" name="friendly_nation_id" placeholder="e.g. 12345" required>
                                </label>

                                <label class="block space-y-2">
                                    <span class="text-sm font-medium">Score</span>
                                    <input type="number" class="input input-bordered input-sm w-full" name="match_score" min="0" max="100" step="0.1" placeholder="auto">
                                </label>
                            </div>

                            <button class="btn btn-outline btn-success btn-sm w-full" type="submit">Assign & Lock Manually</button>
                        </form>

                        <form method="post" action="{{ route('admin.war-counters.finalize', $counter) }}" class="space-y-3">
                            @csrf
                            <label class="label cursor-pointer justify-start gap-3">
                                <input class="checkbox checkbox-sm" type="checkbox" name="notify_in_game" value="1" id="counterNotifyInGame">
                                <span class="label-text">Send in-game mail</span>
                            </label>
                            <label class="label cursor-pointer justify-start gap-3">
                                <input class="checkbox checkbox-sm" type="checkbox" name="notify_discord_room" value="1" id="counterNotifyRoom">
                                <span class="label-text">Create Discord War Room</span>
                            </label>
                            <button class="btn btn-primary btn-sm w-full" type="submit">Finalize Counter</button>
                        </form>

                        <form method="post" action="{{ route('admin.war-counters.archive', $counter) }}">
                            @csrf
                            <button class="btn btn-outline btn-error btn-sm w-full" type="submit">Archive Counter</button>
                        </form>
                    </div>
                </x-card>

                @include('admin.war-room.partials.score-guide')
            </div>

            <div class="space-y-6">
                <x-card title="Proposed Assignments">
                    <x-slot:menu>
                        <span class="text-sm text-base-content/60">Scores reflect availability, readiness, cohesion.</span>
                    </x-slot:menu>

                    <div class="-mx-6 -mb-6 overflow-x-auto rounded-b-box border-t border-base-300">
                        <table class="table table-zebra">
                            <thead>
                            <tr>
                                <th>Friendly Nation</th>
                                <th>Strength</th>
                                <th>Wars</th>
                                <th>Match Score</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            @forelse($assignments as $assignment)
                                @php
                                    $friendly = $assignment->friendlyNation;
                                    $friendlyMilitary = $friendly?->military;
                                    $collapseId = 'counter-meta-'.$assignment->id;
                                    $lastActiveAt = $friendly?->accountProfile?->last_active;
                                @endphp
                                <tbody x-data="{ open: false }">
                                <tr>
                                    <td>
                                        @if($friendly?->id)
                                            <a href="https://politicsandwar.com/nation/id={{ $friendly->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover font-semibold">{{ $friendly->leader_name ?? 'Unknown' }}</a>
                                        @else
                                            <span class="font-semibold">{{ $friendly->leader_name ?? 'Unknown' }}</span>
                                        @endif
                                        <div class="text-sm text-base-content/60">{{ $friendly->nation_name ?? '—' }}</div>
                                    </td>
                                    <td>
                                        <div class="text-sm">Score {{ number_format($friendly->score ?? 0, 2) }} • Cities {{ $friendly->num_cities ?? 0 }}</div>
                                        <div class="text-sm text-base-content/60">Soldiers {{ number_format(optional($friendlyMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($friendlyMilitary)->tanks ?? 0) }}</div>
                                        <div class="text-sm text-base-content/60">Aircraft {{ number_format(optional($friendlyMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($friendlyMilitary)->ships ?? 0) }}</div>
                                    </td>
                                    <td>
                                        <span class="badge badge-ghost" title="Offensive / defensive wars">
                                            {{ $friendly->offensive_wars_count ?? 0 }} / {{ $friendly->defensive_wars_count ?? 0 }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">{{ number_format($assignment->match_score, 1) }}</span>
                                        <div class="text-sm text-base-content/60">Active {{ $lastActiveAt?->diffForHumans() ?? 'Unknown' }}</div>
                                    </td>
                                    <td>
                                        <span class="badge badge-ghost uppercase">{{ $assignment->status }}</span>
                                    </td>
                                    <td>
                                        @if($assignment->status === 'proposed')
                                            <form method="post" action="{{ route('admin.war-counters.assignments.assign', [$counter, $assignment]) }}" class="inline-block">
                                                @csrf
                                                <button class="btn btn-outline btn-success btn-sm" type="submit">Mark Assigned</button>
                                            </form>
                                            <form method="post" action="{{ route('admin.war-counters.assignments.destroy', [$counter, $assignment]) }}" class="inline-block" onsubmit="return confirm('Remove this proposed assignment?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-outline btn-error btn-sm" type="submit">Remove</button>
                                            </form>
                                        @elseif($assignment->status === 'assigned')
                                            <form method="post" action="{{ route('admin.war-counters.assignments.unassign', [$counter, $assignment]) }}" class="inline-block" onsubmit="return confirm('Revert this assignment back to proposed?')">
                                                @csrf
                                                <button class="btn btn-outline btn-warning btn-sm" type="submit">Unassign</button>
                                            </form>
                                        @endif
                                        <button class="btn btn-outline btn-sm" type="button" @click="open = !open" :aria-expanded="open.toString()" aria-controls="{{ $collapseId }}">
                                            Details
                                        </button>
                                    </td>
                                </tr>
                                <tr id="{{ $collapseId }}" x-show="open" x-cloak>
                                    <td colspan="6" class="bg-base-200/60">
                                        @include('admin.war-room.partials.match-breakdown', ['meta' => $assignment->meta ?? []])
                                    </td>
                                </tr>
                                </tbody>
                            @empty
                                <tbody>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-base-content/50">No assignments proposed yet.</td>
                                </tr>
                                </tbody>
                            @endforelse
                        </table>
                    </div>
                </x-card>

                <x-card title="All Candidate Nations (In Range)">
                    <x-slot:menu>
                        <span class="text-sm text-base-content/60">All in-range nations, sorted with recommended options first.</span>
                    </x-slot:menu>

                    <div id="candidate-filter-bar" class="-mx-6 -mt-2 border-y border-base-300 bg-base-200/50 px-6 py-4">
                        <div class="grid gap-3 md:grid-cols-[minmax(0,2.2fr)_repeat(3,minmax(0,1fr))_minmax(0,7rem)]">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Search</span>
                                <input type="search" class="input input-bordered input-sm w-full" id="candidate-filter-search" placeholder="Leader or nation">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Min Cities</span>
                                <input type="number" class="input input-bordered input-sm w-full" id="candidate-filter-min-cities" min="0" step="1" value="0">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Max Cities</span>
                                <input type="number" class="input input-bordered input-sm w-full" id="candidate-filter-max-cities" min="0" step="1" placeholder="Any">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Min Match Score</span>
                                <input type="number" class="input input-bordered input-sm w-full" id="candidate-filter-min-match-score" min="0" max="100" step="0.1" value="0">
                            </label>
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Active in</span>
                                <select class="select select-bordered select-sm w-full" id="candidate-filter-activity">
                                    <option value="all">Any</option>
                                    <option value="24">24h</option>
                                    <option value="72">3d</option>
                                    <option value="168">7d</option>
                                </select>
                            </label>
                        </div>
                        <label class="label mt-2 cursor-pointer justify-start gap-3">
                            <input class="checkbox checkbox-sm" type="checkbox" id="candidate-filter-recommended">
                            <span class="label-text">Recommended only</span>
                        </label>
                    </div>

                    <div class="-mx-6 -mb-6 overflow-x-auto rounded-b-box">
                        <table class="table table-zebra">
                            <thead>
                            <tr>
                                <th>Friendly Nation</th>
                                <th>Strength</th>
                                <th>Wars</th>
                                <th>Match Score</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                                    @forelse($candidates ?? collect() as $row)
                                        @php
                                            $friendly = $row['friendly'];
                                            $friendlyMilitary = $friendly?->military;
                                            $lastActiveAt = $friendly?->accountProfile?->last_active;
                                            $searchBlob = strtolower(trim(($friendly?->leader_name ?? '').' '.($friendly?->nation_name ?? '')));
                                        @endphp
                                        <tr class="candidate-row"
                                            data-search="{{ $searchBlob }}"
                                            data-match-score="{{ (float) ($row['score'] ?? 0) }}"
                                            data-cities="{{ (int) ($friendly->num_cities ?? 0) }}"
                                            data-last-active="{{ $lastActiveAt?->timestamp ?? '' }}"
                                            data-recommended="{{ ($row['recommended'] ?? false) ? '1' : '0' }}">
                                            <td>
                                                @if($friendly?->id)
                                                    <a href="https://politicsandwar.com/nation/id={{ $friendly->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover font-semibold">{{ $friendly->leader_name ?? 'Unknown' }}</a>
                                                @else
                                                    <span class="font-semibold">{{ $friendly->leader_name ?? 'Unknown' }}</span>
                                                @endif
                                                <div class="text-sm text-base-content/60">{{ $friendly->nation_name ?? '—' }}</div>
                                            </td>
                                            <td>
                                                <div class="text-sm">Score {{ number_format($friendly->score ?? 0, 2) }} • Cities {{ $friendly->num_cities ?? 0 }}</div>
                                                <div class="text-sm text-base-content/60">Soldiers {{ number_format(optional($friendlyMilitary)->soldiers ?? 0) }} • Tanks {{ number_format(optional($friendlyMilitary)->tanks ?? 0) }}</div>
                                                <div class="text-sm text-base-content/60">Aircraft {{ number_format(optional($friendlyMilitary)->aircraft ?? 0) }} • Ships {{ number_format(optional($friendlyMilitary)->ships ?? 0) }}</div>
                                            </td>
                                            <td>
                                                <span class="badge badge-ghost" title="Offensive / defensive wars">
                                                    {{ $friendly->offensive_wars_count ?? 0 }} / {{ $friendly->defensive_wars_count ?? 0 }}
                                                </span>
                                            </td>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <span class="badge badge-info">{{ number_format($row['score'] ?? 0, 1) }}</span>
                                                    <span class="badge {{ ($row['recommended'] ?? false) ? 'badge-success' : 'badge-ghost' }}">
                                                        {{ ($row['recommended'] ?? false) ? 'Recommended' : 'Manual only' }}
                                                    </span>
                                                </div>
                                                <div class="text-sm text-base-content/60">Active {{ $lastActiveAt?->diffForHumans() ?? 'Unknown' }}</div>
                                            </td>
                                            <td>
                                                <form method="post" action="{{ route('admin.war-counters.assignments.manual', $counter) }}" class="inline-block">
                                                    @csrf
                                                    <input type="hidden" name="friendly_nation_id" value="{{ $friendly->id }}">
                                                    <input type="hidden" name="match_score" value="{{ $row['score'] ?? '' }}">
                                                    <button class="btn btn-outline btn-primary btn-sm" type="submit">Assign</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr class="candidate-empty-row">
                                            <td colspan="5" class="text-center py-4 text-base-content/50">No nations are in war range.</td>
                                        </tr>
                                    @endforelse
                                    <tr class="candidate-filter-empty hidden">
                                        <td colspan="5" class="text-center py-4 text-base-content/50">No candidates match the current filters.</td>
                                    </tr>
                            </tbody>
                        </table>
                    </div>
                </x-card>

                <div class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                    <x-card title="War Attacks Involving Enemy Nation">
                        <div class="-mx-6 -mt-2 -mb-6 overflow-x-auto rounded-b-box border-t border-base-300">
                            <div class="px-6 pt-4 text-sm text-base-content/60">
                                Most recent 50 attacks where this aggressor was either the attacker or defender.
                            </div>
                            <table class="table table-zebra table-sm">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Role</th>
                                        <th>Attacker</th>
                                        <th>Defender</th>
                                        <th>Type</th>
                                        <th>War</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($enemyWarAttacks as $attack)
                                        <tr>
                                            <td>{{ optional($attack->date)->diffForHumans() }}</td>
                                            <td>
                                                <span class="badge {{ $attack->att_id === $counter->aggressor_nation_id ? 'badge-warning' : 'badge-info' }}">
                                                    {{ $attack->att_id === $counter->aggressor_nation_id ? 'Attacker' : 'Defender' }}
                                                </span>
                                            </td>
                                            <td>
                                                @if($attack->attacker?->id)
                                                    <a href="https://politicsandwar.com/nation/id={{ $attack->attacker->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover">{{ $attack->attacker->leader_name ?? $attack->att_id }}</a>
                                                    <div class="text-sm text-base-content/60">Active {{ $attack->attacker?->accountProfile?->last_active?->diffForHumans() ?? 'Unknown' }}</div>
                                                @else
                                                    {{ $attack->att_id }}
                                                @endif
                                            </td>
                                            <td>
                                                @if($attack->defender?->id)
                                                    <a href="https://politicsandwar.com/nation/id={{ $attack->defender->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover">{{ $attack->defender->leader_name ?? $attack->def_id }}</a>
                                                    <div class="text-sm text-base-content/60">Active {{ $attack->defender?->accountProfile?->last_active?->diffForHumans() ?? 'Unknown' }}</div>
                                                @else
                                                    {{ $attack->def_id }}
                                                @endif
                                            </td>
                                            <td>{{ $attack->type?->name ?? $attack->type }}</td>
                                            <td>
                                                @if($attack->war_id)
                                                    <a href="https://politicsandwar.com/nation/war/timeline/war={{ $attack->war_id }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-primary btn-sm">Timeline</a>
                                                @else
                                                    <span class="text-base-content/50">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-3 text-base-content/50">No war attacks recorded for this enemy nation yet.</td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                            </table>
                        </div>
                    </x-card>

                    <x-card title="Last 30d Wars vs Us">
                        <div class="-mx-6 -mt-2 -mb-6 overflow-x-auto rounded-b-box border-t border-base-300">
                            <table class="table table-zebra table-sm">
                                <thead>
                                    <tr>
                                        <th>Start</th>
                                        <th>Role</th>
                                        <th>Opponent</th>
                                        <th>Status</th>
                                        <th>Link</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($recentWarsAgainstUs ?? collect() as $war)
                                        @php
                                            $isAggAtt = $war->att_id === $counter->aggressor_nation_id;
                                            $opponent = $isAggAtt ? $war->defender : $war->attacker;
                                            $opAlliance = $opponent?->alliance;
                                        @endphp
                                        <tr>
                                            <td>{{ optional($war->date)->diffForHumans() }}</td>
                                            <td>{{ $isAggAtt ? 'Attacking' : 'Defending' }}</td>
                                            <td>
                                                <div class="font-semibold">
                                                    @if($opponent?->id)
                                                        <a href="https://politicsandwar.com/nation/id={{ $opponent->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover">{{ $opponent->leader_name ?? $opponent->id }}</a>
                                                    @else
                                                        —
                                                    @endif
                                                </div>
                                                <div class="text-sm text-base-content/60">
                                                    @if($opAlliance)
                                                        <a href="https://politicsandwar.com/alliance/id={{ $opAlliance->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover">{{ $opAlliance->name }}</a>
                                                    @else
                                                        —
                                                    @endif
                                                </div>
                                                <div class="text-sm text-base-content/60">Active {{ $opponent?->accountProfile?->last_active?->diffForHumans() ?? 'Unknown' }}</div>
                                            </td>
                                            <td>
                                                @if($war->end_date)
                                                    <span class="badge badge-ghost">Ended</span>
                                                @else
                                                    <span class="badge badge-success">Ongoing</span>
                                                @endif
                                            </td>
                                            <td>
                                                <a href="https://politicsandwar.com/nation/war/timeline/war={{ $war->id }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-primary btn-sm">Timeline</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center py-3 text-base-content/50">No wars in last 30 days.</td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                            </table>
                        </div>
                    </x-card>
                </div>

                <x-card title="Counter Cost & Reimbursements">
                    <x-slot:menu>
                        <div class="text-right text-sm text-base-content/60">
                        @if($tradePriceAsOf)
                            24h average trade prices • as of {{ \Carbon\Carbon::parse($tradePriceAsOf)->format('M j, Y') }}
                        @else
                            24h average trade prices unavailable • values default to 0
                        @endif
                        </div>
                    </x-slot:menu>

                    @if($counter->status !== 'active')
                        <div class="alert alert-info">
                            Counter reimbursements unlock when this counter is <strong>active</strong>. Finalize first, then this panel will track costs and payouts.
                        </div>
                    @else
                        <div class="alert border border-base-300 bg-base-200/50 text-base-content">
                            <div class="font-semibold">How costs are valued</div>
                            <div class="text-sm">
                                Resources are reimbursed as actual amounts (gas, munitions, steel, aluminum). Money reimbursement is for unit + infra value only.
                                Unit valuation uses: Soldiers $5, Tanks $60 + 0.5 steel, Aircraft $4,000 + 10 aluminum, Ships $50,000 + 30 steel.
                            </div>
                        </div>

                        @if(! $canManageAccounts)
                            <div class="alert alert-warning">
                                You need the <strong>manage-accounts</strong> permission to issue reimbursements. Cost stats stay visible, but payout actions are disabled.
                            </div>
                        @endif

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
                            <div class="rounded-box border border-base-300 bg-base-200/50 p-3">
                                <div class="text-sm text-base-content/60">Total Value (Units + Infra)</div>
                                    <div class="font-semibold">${{ number_format((float) ($counterCostSummary['total_counter_cost'] ?? 0), 2) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-200/50 p-3">
                                <div class="text-sm text-base-content/60">Value Reimbursed</div>
                                    <div class="font-semibold text-success">${{ number_format((float) ($counterCostSummary['total_reimbursed'] ?? 0), 2) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-200/50 p-3">
                                <div class="text-sm text-base-content/60">Value Outstanding</div>
                                <div class="font-semibold text-warning">${{ number_format((float) ($counterCostSummary['outstanding_total'] ?? 0), 2) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-200/50 p-3">
                                <div class="text-sm text-base-content/60">Resource Burn</div>
                                    <div class="font-semibold">${{ number_format((float) ($counterCostSummary['total_resources_cost'] ?? 0), 2) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-200/50 p-3">
                                <div class="text-sm text-base-content/60">Unit Losses</div>
                                    <div class="font-semibold">${{ number_format((float) ($counterCostSummary['total_unit_loss_cost'] ?? 0), 2) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-200/50 p-3">
                                <div class="text-sm text-base-content/60">Infra Losses</div>
                                    <div class="font-semibold">${{ number_format((float) ($counterCostSummary['total_infra_loss_cost'] ?? 0), 2) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-200/50 p-3">
                                <div class="text-sm text-base-content/60">Wars Tracked</div>
                                    <div class="font-semibold">{{ number_format((int) ($counterCostSummary['war_count'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-200/50 p-3">
                                <div class="text-sm text-base-content/60">Active Wars</div>
                                    <div class="font-semibold">{{ number_format((int) ($counterCostSummary['active_war_count'] ?? 0)) }}</div>
                            </div>
                            <div class="rounded-box border border-base-300 bg-base-200/50 p-3">
                                <div class="text-sm text-base-content/60">Members Involved</div>
                                    <div class="font-semibold">{{ number_format((int) ($counterCostSummary['participant_count'] ?? 0)) }}</div>
                        </div>

                        <div class="mt-6">
                            <h3 class="mb-2 text-base font-semibold">Wars vs Aggressor (Cost Per War)</h3>
                            <div class="overflow-x-auto rounded-box border border-base-300">
                                <table class="table table-zebra table-sm">
                                    <thead>
                                <tr>
                                    <th>Started</th>
                                    <th>Friendly Nation</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Resources Cost</th>
                                    <th>Unit Loss Cost</th>
                                    <th>Infra Loss Cost</th>
                                    <th>Total</th>
                                    <th>War</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($counterCostWars as $warCost)
                                    @php
                                        $friendlyNation = $warCost['friendly_nation'] ?? null;
                                    @endphp
                                    <tr>
                                        <td>{{ optional($warCost['date'] ?? null)->diffForHumans() ?? '—' }}</td>
                                        <td>
                                            @if($friendlyNation?->id)
                                                <a href="https://politicsandwar.com/nation/id={{ $friendlyNation->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover">
                                                    {{ $friendlyNation->leader_name ?? $friendlyNation->id }}
                                                </a>
                                            @else
                                                —
                                            @endif
                                            <div class="text-sm text-base-content/60">{{ $friendlyNation?->nation_name ?? 'Unknown nation' }}</div>
                                        </td>
                                        <td>
                                            <span class="badge {{ ($warCost['friendly_role'] ?? '') === 'attacker' ? 'badge-error' : 'badge-primary' }}">
                                                {{ ucfirst($warCost['friendly_role'] ?? 'unknown') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge {{ ($warCost['is_active'] ?? false) ? 'badge-success' : 'badge-ghost' }}">
                                                {{ ($warCost['is_active'] ?? false) ? 'Active' : 'Ended' }}
                                            </span>
                                        </td>
                                        <td>
                                            ${{ number_format((float) ($warCost['resources_cost'] ?? 0), 2) }}
                                            <div class="text-sm text-base-content/60">
                                                G {{ number_format((float) ($warCost['resources_used']['gasoline'] ?? 0), 2) }}
                                                • M {{ number_format((float) ($warCost['resources_used']['munitions'] ?? 0), 2) }}
                                                • S {{ number_format((float) ($warCost['resources_used']['steel'] ?? 0), 2) }}
                                                • A {{ number_format((float) ($warCost['resources_used']['aluminum'] ?? 0), 2) }}
                                            </div>
                                        </td>
                                        <td>
                                            ${{ number_format((float) ($warCost['unit_loss_cost'] ?? 0), 2) }}
                                            <div class="text-sm text-base-content/60">
                                                S {{ number_format((int) ($warCost['unit_losses']['soldiers'] ?? 0)) }}
                                                • T {{ number_format((int) ($warCost['unit_losses']['tanks'] ?? 0)) }}
                                                • A {{ number_format((int) ($warCost['unit_losses']['aircraft'] ?? 0)) }}
                                                • Sh {{ number_format((int) ($warCost['unit_losses']['ships'] ?? 0)) }}
                                            </div>
                                        </td>
                                        <td>${{ number_format((float) ($warCost['infra_loss_cost'] ?? 0), 2) }}</td>
                                        <td class="font-semibold">${{ number_format((float) ($warCost['total_cost'] ?? 0), 2) }}</td>
                                        <td>
                                            <a href="https://politicsandwar.com/nation/war/timeline/war={{ $warCost['war_id'] ?? 0 }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-primary btn-sm">
                                                Timeline
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center py-3 text-base-content/50">No counter wars found yet for this aggressor window.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                            </div>
                        </div>

                        <div class="mt-6">
                            <h3 class="mb-2 text-base font-semibold">Reimburse Members</h3>
                            <div class="mb-2 text-sm text-base-content/60">
                            Includes members actively involved in this counter, including the original defender when detected in counter wars.
                            </div>
                            <div class="overflow-x-auto rounded-box border border-base-300">
                                <table class="table table-zebra">
                                    <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Wars</th>
                                    <th>Suggested Reimbursement</th>
                                    <th>Deposit Account</th>
                                    <th>Adjustable Reimbursement</th>
                                    <th class="text-right">Action</th>
                                </tr>
                                </thead>
                                    <tbody>
                                @forelse($counterCostParticipants as $participant)
                                    @php
                                        $nation = $participant['nation'] ?? null;
                                        $nationId = (int) ($participant['nation_id'] ?? 0);
                                        $isActiveRow = $activeReimbursementNationId === $nationId;
                                        $rowGasoline = (float) ($isActiveRow ? old('gasoline', $participant['outstanding_resources']['gasoline'] ?? 0) : ($participant['outstanding_resources']['gasoline'] ?? 0));
                                        $rowMunitions = (float) ($isActiveRow ? old('munitions', $participant['outstanding_resources']['munitions'] ?? 0) : ($participant['outstanding_resources']['munitions'] ?? 0));
                                        $rowSteel = (float) ($isActiveRow ? old('steel', $participant['outstanding_resources']['steel'] ?? 0) : ($participant['outstanding_resources']['steel'] ?? 0));
                                        $rowAluminum = (float) ($isActiveRow ? old('aluminum', $participant['outstanding_resources']['aluminum'] ?? 0) : ($participant['outstanding_resources']['aluminum'] ?? 0));
                                        $rowUnits = (float) ($isActiveRow ? old('unit_loss_cost', $participant['outstanding_unit_loss_cost'] ?? 0) : ($participant['outstanding_unit_loss_cost'] ?? 0));
                                        $rowInfra = (float) ($isActiveRow ? old('infra_loss_cost', $participant['outstanding_infra_loss_cost'] ?? 0) : ($participant['outstanding_infra_loss_cost'] ?? 0));
                                        $rowTotal = $rowUnits + $rowInfra;
                                        $rowAccounts = $participant['accounts'] ?? collect();
                                        $rowRecommendedAccountId = $participant['recommended_account_id'] ?? null;
                                        $rowSelectedAccountId = (int) ($isActiveRow ? old('account_id', $rowRecommendedAccountId) : $rowRecommendedAccountId);
                                        $rowCanSubmit = $canManageAccounts && $rowAccounts->isNotEmpty();
                                        $rowTotalTargetId = 'reimbursement-total-'.$nationId;
                                        $reimbursementFormId = 'counter-reimbursement-form-'.$nationId;
                                    @endphp
                                    <tr data-counter-reimbursement-row data-total-target="{{ $rowTotalTargetId }}">
                                        <td>
                                            @if($nation?->id)
                                                <a href="https://politicsandwar.com/nation/id={{ $nation->id }}" target="_blank" rel="noopener noreferrer" class="font-semibold">
                                                    {{ $nation->leader_name ?? $nation->id }}
                                                </a>
                                            @else
                                                <span class="font-semibold">Nation #{{ $nationId }}</span>
                                            @endif
                                            <div class="text-sm text-base-content/60">{{ $nation?->nation_name ?? 'Unknown nation' }}</div>
                                            <div class="text-sm text-base-content/60">
                                                Already reimbursed: ${{ number_format((float) ($participant['reimbursed_total'] ?? 0), 2) }}
                                                @if((int) ($participant['reimbursement_count'] ?? 0) > 0)
                                                    • {{ (int) $participant['reimbursement_count'] }} payout(s)
                                                @endif
                                            </div>
                                            <div class="text-sm text-base-content/60">
                                                Resource reimbursed:
                                                Gas {{ number_format((float) ($participant['reimbursed_resources']['gasoline'] ?? 0), 2) }}
                                                • Mun {{ number_format((float) ($participant['reimbursed_resources']['munitions'] ?? 0), 2) }}
                                                • Steel {{ number_format((float) ($participant['reimbursed_resources']['steel'] ?? 0), 2) }}
                                                • Alum {{ number_format((float) ($participant['reimbursed_resources']['aluminum'] ?? 0), 2) }}
                                            </div>
                                            <div class="text-sm text-base-content/60">
                                                Value outstanding: ${{ number_format((float) ($participant['outstanding_cost'] ?? 0), 2) }}
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-sm">{{ number_format((int) ($participant['war_count'] ?? 0)) }} tracked</div>
                                            <div class="text-sm text-base-content/60">{{ number_format((int) ($participant['active_war_count'] ?? 0)) }} active</div>
                                        </td>
                                        <td>
                                            <div class="text-sm font-semibold">Resource Amounts (remaining)</div>
                                            <div class="text-sm text-base-content/60">
                                                Gas {{ number_format((float) ($participant['outstanding_resources']['gasoline'] ?? 0), 2) }}
                                                • Mun {{ number_format((float) ($participant['outstanding_resources']['munitions'] ?? 0), 2) }}
                                                • Steel {{ number_format((float) ($participant['outstanding_resources']['steel'] ?? 0), 2) }}
                                                • Alum {{ number_format((float) ($participant['outstanding_resources']['aluminum'] ?? 0), 2) }}
                                            </div>
                                            <div class="mt-1 text-sm">Unit Value: ${{ number_format((float) ($participant['outstanding_unit_loss_cost'] ?? 0), 2) }}</div>
                                            <div class="text-sm">Infra Value: ${{ number_format((float) ($participant['outstanding_infra_loss_cost'] ?? 0), 2) }}</div>
                                            <div class="text-sm font-semibold">Money Total: ${{ number_format((float) ($participant['outstanding_cost'] ?? 0), 2) }}</div>
                                            <div class="text-sm text-base-content/60">Defaults use remaining amount per category/resource.</div>
                                        </td>
                                        <td>
                                                <select name="account_id" class="select select-bordered select-sm mb-2 w-full" form="{{ $reimbursementFormId }}" @disabled(! $rowCanSubmit)>
                                                    @forelse($rowAccounts as $account)
                                                        <option value="{{ $account->id }}" @selected($rowSelectedAccountId === (int) $account->id)>
                                                            {{ $account->name }} @if($account->frozen) (Frozen) @endif
                                                        </option>
                                                    @empty
                                                        <option value="">No accounts available</option>
                                                    @endforelse
                                                </select>
                                                <div class="text-sm text-base-content/60">
                                                    @if($rowAccounts->isEmpty())
                                                        Member has no accounts to receive reimbursement.
                                                    @else
                                                        Choose which account receives the deposit.
                                                    @endif
                                                </div>
                                        </td>
                                        <td>
                                            <div class="grid gap-3 lg:grid-cols-2 2xl:grid-cols-4">
                                                <label class="block space-y-2">
                                                    <span class="text-sm font-medium">Gasoline</span>
                                                    <input type="number"
                                                           step="0.01"
                                                           min="0"
                                                           name="gasoline"
                                                           value="{{ number_format($rowGasoline, 2, '.', '') }}"
                                                           form="{{ $reimbursementFormId }}"
                                                           class="input input-bordered input-sm w-full"
                                                           @disabled(! $rowCanSubmit)>
                                                </label>
                                                <label class="block space-y-2">
                                                    <span class="text-sm font-medium">Munitions</span>
                                                    <input type="number"
                                                           step="0.01"
                                                           min="0"
                                                           name="munitions"
                                                           value="{{ number_format($rowMunitions, 2, '.', '') }}"
                                                           form="{{ $reimbursementFormId }}"
                                                           class="input input-bordered input-sm w-full"
                                                           @disabled(! $rowCanSubmit)>
                                                </label>
                                                <label class="block space-y-2">
                                                    <span class="text-sm font-medium">Steel</span>
                                                    <input type="number"
                                                           step="0.01"
                                                           min="0"
                                                           name="steel"
                                                           value="{{ number_format($rowSteel, 2, '.', '') }}"
                                                           form="{{ $reimbursementFormId }}"
                                                           class="input input-bordered input-sm w-full"
                                                           @disabled(! $rowCanSubmit)>
                                                </label>
                                                <label class="block space-y-2">
                                                    <span class="text-sm font-medium">Aluminum</span>
                                                    <input type="number"
                                                           step="0.01"
                                                           min="0"
                                                           name="aluminum"
                                                           value="{{ number_format($rowAluminum, 2, '.', '') }}"
                                                           form="{{ $reimbursementFormId }}"
                                                           class="input input-bordered input-sm w-full"
                                                           @disabled(! $rowCanSubmit)>
                                                </label>
                                                <label class="block space-y-2">
                                                    <span class="text-sm font-medium">Unit Value ($)</span>
                                                    <input type="number"
                                                           step="0.01"
                                                           min="0"
                                                           name="unit_loss_cost"
                                                           value="{{ number_format($rowUnits, 2, '.', '') }}"
                                                           form="{{ $reimbursementFormId }}"
                                                           class="input input-bordered input-sm w-full counter-cost-input"
                                                           @disabled(! $rowCanSubmit)>
                                                </label>
                                                <label class="block space-y-2">
                                                    <span class="text-sm font-medium">Infra Value ($)</span>
                                                    <input type="number"
                                                           step="0.01"
                                                           min="0"
                                                           name="infra_loss_cost"
                                                           value="{{ number_format($rowInfra, 2, '.', '') }}"
                                                           form="{{ $reimbursementFormId }}"
                                                           class="input input-bordered input-sm w-full counter-cost-input"
                                                           @disabled(! $rowCanSubmit)>
                                                </label>
                                                <label class="block space-y-2 lg:col-span-2 2xl:col-span-4">
                                                    <span class="text-sm font-medium">Admin Note (optional)</span>
                                                    <input type="text"
                                                           name="note"
                                                           maxlength="255"
                                                           class="input input-bordered input-sm w-full"
                                                           value="{{ $isActiveRow ? old('note') : '' }}"
                                                           placeholder="Optional override reason"
                                                           form="{{ $reimbursementFormId }}"
                                                           @disabled(! $rowCanSubmit)>
                                                </label>
                                            </div>
                                        </td>
                                        <td class="text-right">
                                            <form id="{{ $reimbursementFormId }}" method="post" action="{{ route('admin.war-counters.reimbursements.store', $counter) }}" class="hidden">
                                                @csrf
                                                <input type="hidden" name="nation_id" value="{{ $nationId }}">
                                            </form>
                                            <div class="mb-1 text-sm text-base-content/60">Money reimbursement total</div>
                                            <div class="font-semibold mb-2" id="{{ $rowTotalTargetId }}">${{ number_format($rowTotal, 2) }}</div>
                                            <button type="submit"
                                                    class="btn btn-primary btn-sm"
                                                    form="{{ $reimbursementFormId }}"
                                                    @disabled(! $rowCanSubmit)>
                                                Reimburse
                                            </button>
                                            @if(! $canManageAccounts)
                                                <div class="mt-1 text-sm text-warning">Needs manage-accounts</div>
                                            @elseif($rowAccounts->isEmpty())
                                                <div class="mt-1 text-sm text-warning">No destination account</div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-3 text-base-content/50">No member cost records available yet.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                            </div>
                        </div>

                        <div class="mt-6">
                            <h3 class="mb-2 text-base font-semibold">Recent Reimbursements</h3>
                            <div class="overflow-x-auto rounded-box border border-base-300">
                                <table class="table table-zebra table-sm">
                                    <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Member</th>
                                    <th>Account</th>
                                    <th>Breakdown</th>
                                    <th>Money</th>
                                    <th>Admin</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($counterRecentReimbursements as $reimbursement)
                                    <tr>
                                        <td>{{ optional($reimbursement->created_at)->diffForHumans() ?? '—' }}</td>
                                        <td>
                                            @if($reimbursement->nation?->id)
                                                <a href="https://politicsandwar.com/nation/id={{ $reimbursement->nation->id }}" target="_blank" rel="noopener noreferrer" class="link link-hover">
                                                    {{ $reimbursement->nation->leader_name ?? $reimbursement->nation->id }}
                                                </a>
                                            @else
                                                Nation #{{ $reimbursement->nation_id }}
                                            @endif
                                        </td>
                                        <td>{{ $reimbursement->account?->name ?? '—' }}</td>
                                        <td class="text-sm">
                                            Gas {{ number_format((float) $reimbursement->gasoline, 2) }} •
                                            Mun {{ number_format((float) $reimbursement->munitions, 2) }} •
                                            Steel {{ number_format((float) $reimbursement->steel, 2) }} •
                                            Alum {{ number_format((float) $reimbursement->aluminum, 2) }}
                                            <div>Units ${{ number_format((float) $reimbursement->unit_loss_cost, 2) }} • Infra ${{ number_format((float) $reimbursement->infra_loss_cost, 2) }}</div>
                                        </td>
                                        <td class="font-semibold">${{ number_format((float) $reimbursement->total_cost, 2) }}</td>
                                        <td>{{ $reimbursement->reimbursedByUser?->name ?? 'System' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-3 text-base-content/50">No reimbursements issued for this counter yet.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                            </div>
                        </div>
                    @endif
                </x-card>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const nowTs = () => Math.floor(Date.now() / 1000);

            const asNumber = (value) => {
                const parsed = Number(value);
                return Number.isFinite(parsed) ? parsed : 0;
            };

            const formatCurrency = (value) => {
                const amount = Number.isFinite(value) ? value : 0;

                return `$${amount.toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                })}`;
            };

            const withinActivityWindow = (rowTs, windowHours) => {
                if (!windowHours || windowHours === 'all') {
                    return true;
                }

                const timestamp = asNumber(rowTs);
                if (!timestamp) {
                    return false;
                }

                const maxSeconds = asNumber(windowHours) * 3600;
                return nowTs() - timestamp <= maxSeconds;
            };

            const initCandidateFilters = () => {
                const rows = Array.from(document.querySelectorAll('.candidate-row'));
                if (!rows.length) {
                    return;
                }

                const search = document.getElementById('candidate-filter-search');
                const minCities = document.getElementById('candidate-filter-min-cities');
                const maxCities = document.getElementById('candidate-filter-max-cities');
                const minMatchScore = document.getElementById('candidate-filter-min-match-score');
                const activity = document.getElementById('candidate-filter-activity');
                const recommendedOnly = document.getElementById('candidate-filter-recommended');
                const emptyState = document.querySelector('.candidate-filter-empty');

                const apply = () => {
                    let visibleRows = 0;
                    const query = (search?.value || '').trim().toLowerCase();
                    const minCitiesValue = asNumber(minCities?.value || 0);
                    const maxCitiesRaw = (maxCities?.value || '').trim();
                    const hasMaxCities = maxCitiesRaw !== '';
                    const maxCitiesValue = asNumber(maxCitiesRaw);
                    const minMatchScoreValue = asNumber(minMatchScore?.value || 0);
                    const activityValue = activity?.value || 'all';
                    const onlyRecommended = Boolean(recommendedOnly?.checked);

                    rows.forEach((row) => {
                        const cityCount = asNumber(row.dataset.cities || 0);
                        const matchesSearch = !query || (row.dataset.search || '').includes(query);
                        const matchesMinCities = cityCount >= minCitiesValue;
                        const matchesMaxCities = !hasMaxCities || cityCount <= maxCitiesValue;
                        const matchesMatchScore = asNumber(row.dataset.matchScore || 0) >= minMatchScoreValue;
                        const matchesActivity = withinActivityWindow(row.dataset.lastActive || '', activityValue);
                        const matchesRecommended = !onlyRecommended || row.dataset.recommended === '1';
                        const show = matchesSearch
                            && matchesMinCities
                            && matchesMaxCities
                            && matchesMatchScore
                            && matchesActivity
                            && matchesRecommended;

                        row.classList.toggle('hidden', !show);

                        if (show) {
                            visibleRows++;
                        }
                    });

                    if (emptyState) {
                        emptyState.classList.toggle('hidden', visibleRows !== 0);
                    }
                };

                [search, minCities, maxCities, minMatchScore, activity, recommendedOnly].forEach((element) => {
                    if (!element) {
                        return;
                    }

                    element.addEventListener('input', apply);
                    element.addEventListener('change', apply);
                });

                apply();
            };

            const initReimbursementRows = () => {
                const rows = Array.from(document.querySelectorAll('[data-counter-reimbursement-row]'));
                if (!rows.length) {
                    return;
                }

                rows.forEach((row) => {
                    const totalTargetId = row.dataset.totalTarget || '';
                    const totalTarget = totalTargetId ? document.getElementById(totalTargetId) : null;
                    const inputs = Array.from(row.querySelectorAll('.counter-cost-input'));

                    const apply = () => {
                        const total = inputs.reduce((sum, input) => sum + asNumber(input.value), 0);

                        if (totalTarget) {
                            totalTarget.textContent = formatCurrency(total);
                        }
                    };

                    inputs.forEach((input) => {
                        input.addEventListener('input', apply);
                        input.addEventListener('change', apply);
                    });

                    apply();
                });
            };

            initCandidateFilters();
            initReimbursementRows();
        })();
    </script>
@endpush

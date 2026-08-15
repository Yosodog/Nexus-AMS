@extends('layouts.admin')

@section('title', 'Fast Counters | Milcom')

@php
    $incidents = $incidents ?? collect();
    $incidentRows = collect(is_object($incidents) && method_exists($incidents, 'items') ? $incidents->items() : $incidents)->take(50);
    $selectedIncident = $selectedIncident ?? $incidentRows->first();
    $selectedObjective = $selectedObjective ?? data_get($selectedIncident, 'objective');
    $recommendation = $recommendation ?? data_get($selectedObjective, 'recommendation', data_get($selectedIncident, 'recommendation', []));
    $recommendedTeam = collect($recommendedTeam ?? data_get($recommendation, 'proposed_team', data_get($selectedObjective, 'assignments', [])))->take(3);
    $alternativeTeams = collect($alternativeTeams ?? data_get($recommendation, 'alternatives', []))->take(3);
    $blockers = collect($blockers ?? data_get($recommendation, 'blockers', data_get($selectedObjective, 'blockers', [])));
    $hardBlockers = $blockers->filter(static fn ($blocker): bool => is_string($blocker) || (bool) data_get($blocker, 'hard', true));
    $warnings = collect($warnings ?? data_get($recommendation, 'warnings', data_get($selectedObjective, 'warnings', [])));
    $preflight = collect($preflight ?? data_get($selectedObjective, 'preflight', []));
    $timeline = collect($timeline ?? data_get($selectedIncident, 'events', data_get($selectedObjective, 'events', [])))->take(20);
    $summary = $summary ?? [];
    $filters = $filters ?? request()->only(['filter', 'search']);
    $activeFilter = (string) data_get($filters, 'filter', 'urgent');
    $apiBase = $apiBase ?? url('/api/v1/milcom');
    $generationVersion = (int) data_get($selectedObjective, 'operation.generation_version', data_get($selectedObjective, 'generation_version', 1));
    $objectiveId = (int) data_get($selectedObjective, 'id', 0);
    $objectiveStatus = (string) data_get($selectedObjective, 'status.value', data_get($selectedObjective, 'status', 'review'));
    $dispatchStatus = (string) data_get($selectedObjective, 'dispatch.status', '');
    $dispatchFailed = $dispatchStatus === 'failed';
    $canDispatch = in_array($objectiveStatus, ['pending', 'review', 'blocked', 'approved'], true) && ! $dispatchFailed;
    $recommendationStatus = (string) data_get($recommendation, 'status', '');
    $recommendationRunId = (int) data_get($recommendation, 'run_id', 0);
    $recommendationProgress = (int) data_get($recommendation, 'progress_percent', 0);
    $recommendationTrigger = (string) data_get($recommendation, 'trigger', '');
    $recommendationActive = in_array($recommendationStatus, ['queued', 'running'], true);
    $relativeTime = static function ($value): string {
        if (is_object($value) && method_exists($value, 'diffForHumans')) {
            return $value->diffForHumans();
        }

        return filled($value) ? (string) $value : 'Unknown';
    };
    $statusTone = static fn (string $status): string => match ($status) {
        'covered_by_plan', 'dispatched', 'engaged', 'resolved', 'completed', 'ready' => 'nexus-status--success',
        'blocked', 'failed', 'conflict' => 'nexus-status--error',
        'countering', 'running', 'queued' => 'nexus-status--info',
        'new', 'review', 'stale', 'warning' => 'nexus-status--warning',
        default => 'nexus-status--neutral',
    };
    $statusLabel = static fn (string $status): string => match ($status) {
        'covered_by_plan' => 'Covered by plan',
        'countering' => 'Counter in progress',
        'dispatching' => 'Creating Discord room',
        'dispatched' => 'Sent to Discord',
        'engaged' => 'War started',
        'pending' => 'Waiting for review',
        'review' => 'Ready for review',
        default => str($status)->headline()->toString(),
    };
@endphp

@section('content')
    <div
        data-milcom-app="counter-queue"
        data-api-base="{{ $apiBase }}"
        data-incidents-endpoint="{{ $apiBase }}/incidents?filter={{ urlencode($activeFilter) }}&limit=50"
        data-incident-detail-template="{{ $apiBase }}/incidents/{id}"
        data-generation-version="{{ $generationVersion }}"
        data-recommendation-run-id="{{ $recommendationRunId ?: '' }}"
        data-recommendation-status="{{ $recommendationStatus }}"
        data-recommendation-progress="{{ $recommendationProgress }}"
        data-recommendation-trigger="{{ $recommendationTrigger }}"
        class="contents"
    >
        <x-header title="Fast Counters" separator use-h1>
            <x-slot:subtitle>Every defensive war appears here. Review the team and final checks, then approve it and create the Discord room.</x-slot:subtitle>
            <x-slot:actions>
                <button type="button" class="btn btn-outline" data-milcom-refresh>
                    <x-icon name="o-arrow-path" class="size-5" aria-hidden="true" />
                    Refresh
                </button>
            </x-slot:actions>
        </x-header>

        @include('admin.milcom.partials.navigation', ['milcomCurrent' => 'counters'])

        <div class="{{ $recommendationActive ? 'flex' : 'hidden' }} alert alert-info items-center gap-3" role="status" data-milcom-recommendation-progress>
            <x-icon name="o-arrow-path" class="size-5 shrink-0 animate-spin motion-reduce:animate-none" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-3 text-sm"><span class="font-semibold" data-milcom-progress-label>{{ $recommendationTrigger === 'counter_auto_refresh' ? 'Refreshing counter team' : ($recommendationActive ? 'Building team' : 'Team progress') }}</span><span class="tabular-nums" data-milcom-progress-value>{{ $recommendationProgress }}%</span></div>
                <progress class="progress progress-info mt-2 h-1.5 w-full" value="{{ $recommendationProgress }}" max="100" data-milcom-progress-bar></progress>
            </div>
        </div>

        <section class="nexus-metrics" aria-label="Counter queue summary">
            <div class="nexus-metric bg-error/5">
                <span class="nexus-stat-label">Urgent</span>
                <strong class="nexus-stat-value text-error" data-milcom-value="urgent">{{ number_format((int) data_get($summary, 'urgent', 0)) }}</strong>
                <span class="nexus-stat-helper">Needs review</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Building teams</span>
                <strong class="nexus-stat-value" data-milcom-value="recommending">{{ number_format((int) data_get($summary, 'recommending', 0)) }}</strong>
                <span class="nexus-stat-helper">Work in progress</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Covered by plan</span>
                <strong class="nexus-stat-value" data-milcom-value="covered_by_plan">{{ number_format((int) data_get($summary, 'covered_by_plan', 0)) }}</strong>
                <span class="nexus-stat-helper">Already assigned in a plan</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Discord room errors</span>
                <strong class="nexus-stat-value" data-milcom-value="dispatch_failures">{{ number_format((int) data_get($summary, 'dispatch_failures', 0)) }}</strong>
                <span class="nexus-stat-helper">Discord rooms that failed</span>
            </div>
            <a href="{{ url('/admin/milcom/counters') }}?filter=overdue" class="nexus-metric bg-warning/5">
                <span class="nexus-stat-label">Overdue declarations</span>
                <strong class="nexus-stat-value text-warning" data-milcom-value="overdue_declarations">{{ number_format((int) data_get($summary, 'overdue_declarations', 0)) }}</strong>
                <span class="nexus-stat-helper">Assigned teams that have not declared</span>
            </a>
        </section>

        <div class="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(22rem,0.72fr)_minmax(34rem,1.28fr)]">
            <section class="nexus-panel min-w-0" aria-labelledby="incident-queue-title">
                <div class="nexus-panel__header">
                    <div>
                        <h2 id="incident-queue-title" class="nexus-section-title">Incoming wars</h2>
                        <p class="mt-1 text-sm text-base-content/65">Use J/K or the arrow keys to move.</p>
                    </div>
                    <span class="badge badge-error badge-soft">{{ number_format($incidentRows->count()) }} shown</span>
                </div>

                <form method="GET" action="{{ url('/admin/milcom/counters') }}" class="grid gap-3 border-b border-base-300 p-3 sm:grid-cols-[minmax(0,1fr)_auto]" role="search">
                    <label class="input input-sm w-full">
                        <x-icon name="o-magnifying-glass" class="size-4 opacity-55" aria-hidden="true" />
                        <input type="search" name="search" value="{{ data_get($filters, 'search') }}" placeholder="Aggressor or defender" aria-label="Search incoming wars" data-milcom-search>
                        <kbd class="kbd kbd-sm hidden border-base-300 bg-base-200 sm:inline-flex">/</kbd>
                    </label>
                    <div class="flex gap-2">
                        <select name="filter" class="select select-sm min-w-36 flex-1" aria-label="War filter" data-milcom-filter>
                            <option value="urgent" @selected($activeFilter === 'urgent')>Urgent</option>
                            <option value="all" @selected($activeFilter === 'all')>All open</option>
                            <option value="recommending" @selected($activeFilter === 'recommending')>Building team</option>
                            <option value="blocked" @selected($activeFilter === 'blocked')>Blocked</option>
                            <option value="overdue" @selected($activeFilter === 'overdue')>Overdue declarations</option>
                            <option value="covered_by_plan" @selected($activeFilter === 'covered_by_plan')>Covered by plan</option>
                        </select>
                        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
                    </div>
                </form>

                <div
                    class="divide-y divide-base-300"
                    role="listbox"
                    aria-label="Incoming wars"
                    data-milcom-incident-list
                    aria-busy="false"
                >
                    @forelse ($incidentRows as $incident)
                        @php
                            $incidentId = (int) data_get($incident, 'id', 0);
                            $isSelected = (int) data_get($selectedIncident, 'id', 0) === $incidentId;
                            $status = (string) data_get($incident, 'handling_state.value', data_get($incident, 'status.value', data_get($incident, 'status', 'new')));
                            $aggressor = data_get($incident, 'aggressor', data_get($incident, 'attacker', []));
                            $defender = data_get($incident, 'attacked', data_get($incident, 'defender', []));
                            $incidentBlockers = (int) data_get($incident, 'blocker_count', 0);
                        @endphp
                        <article class="relative transition-colors {{ $isSelected ? 'bg-primary/7' : 'hover:bg-base-200/60' }}">
                            <button
                                type="button"
                                class="absolute inset-0 z-0 w-full text-left focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary"
                                role="option"
                                aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                                aria-label="View counter against {{ data_get($aggressor, 'nation_name', data_get($incident, 'aggressor_name', 'unknown aggressor')) }}"
                                tabindex="{{ $isSelected || ($loop->first && ! $selectedIncident) ? '0' : '-1' }}"
                                data-milcom-select-incident
                                data-incident-id="{{ $incidentId }}"
                            ></button>
                            <div class="pointer-events-none relative z-10 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate font-semibold">
                                            <x-pw-nation-link
                                                :nation-id="data_get($aggressor, 'id', data_get($incident, 'aggressor_nation_id'))"
                                                :label="data_get($aggressor, 'nation_name', data_get($incident, 'aggressor_name', 'Unknown aggressor'))"
                                                class="pointer-events-auto relative z-20"
                                            />
                                        </h3>
                                        @if ((bool) data_get($incident, 'is_urgent', true))
                                            <span class="badge badge-error badge-soft">Urgent</span>
                                        @endif
                                        @if ($incidentBlockers > 0)
                                            <span class="badge badge-error badge-outline">{{ $incidentBlockers }} blockers</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 truncate text-xs nexus-text-muted">
                                        <x-pw-nation-link
                                            :nation-id="data_get($aggressor, 'id', data_get($incident, 'aggressor_nation_id'))"
                                            :label="data_get($aggressor, 'leader_name', 'Unknown leader')"
                                            class="pointer-events-auto relative z-20"
                                        />
                                        <span aria-hidden="true">→</span>
                                        <x-pw-nation-link
                                            :nation-id="data_get($defender, 'id', data_get($incident, 'attacked_nation_id'))"
                                            :label="data_get($defender, 'nation_name', data_get($incident, 'defender_name', 'Friendly nation'))"
                                            class="pointer-events-auto relative z-20"
                                        />
                                    </p>
                                </div>
                                <span class="nexus-status {{ $statusTone($status) }} shrink-0">{{ $statusLabel($status) }}</span>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3 text-xs nexus-text-muted">
                                <span>Detected {{ $relativeTime(data_get($incident, 'detected_at')) }}</span>
                                <span class="font-semibold tabular-nums">{{ number_format((int) data_get($incident, 'recommended_depth', 0)) }}/3 assigned</span>
                            </div>
                            </div>
                        </article>
                    @empty
                        <div class="nexus-empty-state" data-milcom-incident-empty>
                            <x-icon name="o-shield-check" class="size-9 text-success" aria-hidden="true" />
                            <div>
                                <h3 class="text-lg font-semibold">No counters need attention</h3>
                                <p class="mt-1 text-sm text-base-content/65">New defensive wars appear here, including wars already covered by a plan.</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                @if (is_object($incidents) && method_exists($incidents, 'hasPages') && $incidents->hasPages())
                    <div class="nexus-panel__footer">{{ $incidents->links() }}</div>
                @else
                    <div class="nexus-panel__footer flex items-center justify-between text-xs nexus-text-muted">
                        <span>{{ number_format($incidentRows->count()) }} wars on this page</span>
                        <span>Up to 50</span>
                    </div>
                @endif
            </section>

            <section class="nexus-panel min-w-0 xl:sticky xl:top-4" aria-labelledby="counter-preflight-title" data-milcom-counter-inspector>
                <div class="hidden" data-milcom-inspector-loading aria-hidden="true">
                    <div class="animate-pulse space-y-5 p-5 motion-reduce:animate-none">
                        <div class="h-7 w-2/3 rounded bg-base-300"></div>
                        <div class="grid grid-cols-2 gap-3"><div class="h-20 rounded bg-base-300"></div><div class="h-20 rounded bg-base-300"></div></div>
                        <div class="h-40 rounded bg-base-300"></div>
                        <div class="h-28 rounded bg-base-300"></div>
                    </div>
                </div>

                <div class="{{ $selectedIncident ? 'hidden' : '' }} nexus-empty-state min-h-96" data-milcom-inspector-empty>
                    <x-icon name="o-bolt" class="size-9 nexus-text-muted" aria-hidden="true" />
                    <div>
                        <h2 class="text-lg font-semibold">Select a war</h2>
                        <p class="mt-1 text-sm text-base-content/65">Select a war to see the team, checks, Discord room, and timeline.</p>
                    </div>
                </div>

                <div class="{{ $selectedIncident ? '' : 'hidden' }}" data-milcom-inspector-content>
                    @php
                        $aggressor = data_get($selectedObjective, 'target', data_get($selectedIncident, 'aggressor', data_get($selectedIncident, 'attacker', [])));
                        $defender = data_get($selectedIncident, 'attacked', data_get($selectedIncident, 'defender', []));
                        $incidentStatus = (string) data_get($selectedIncident, 'handling_state.value', data_get($selectedIncident, 'status', 'new'));
                    @endphp
                    <div class="nexus-panel__header">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 id="counter-preflight-title" class="nexus-section-title truncate">
                                    <x-pw-nation-link
                                        :nation-id="data_get($aggressor, 'id', data_get($selectedIncident, 'aggressor_nation_id'))"
                                        :label="data_get($aggressor, 'nation_name', data_get($selectedIncident, 'aggressor_name', 'Selected target'))"
                                        data-milcom-field="aggressor_name"
                                    />
                                </h2>
                                <span class="badge badge-error badge-soft">Counter</span>
                            </div>
                            <p class="mt-1 text-sm nexus-text-muted">
                                Attacked
                                <x-pw-nation-link
                                    :nation-id="data_get($defender, 'id', data_get($selectedIncident, 'attacked_nation_id'))"
                                    :label="data_get($defender, 'nation_name', data_get($selectedIncident, 'defender_name', 'friendly nation'))"
                                    data-milcom-field="defender_name"
                                />
                                <span aria-hidden="true">·</span>
                                War #<span class="tabular-nums" data-milcom-field="war_id">{{ data_get($selectedIncident, 'war_id', 'Unknown') }}</span>
                            </p>
                        </div>
                        <span class="nexus-status {{ $statusTone($incidentStatus) }}" data-milcom-field="status">{{ $statusLabel($incidentStatus) }}</span>
                    </div>

                    <div class="{{ data_get($selectedObjective, 'declaration_overdue', false) ? 'flex' : 'hidden' }} alert alert-warning mx-4 mt-4 items-start gap-3 md:mx-5" role="alert" data-milcom-declaration-overdue>
                        <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        <div>
                            <p class="font-semibold">Declaration overdue</p>
                            <p class="mt-1 text-sm">This team remains assigned, but no declaration was detected by <span class="font-semibold" data-milcom-field="declaration_deadline">{{ $relativeTime(data_get($selectedObjective, 'deadline_at')) }}</span>. Milcom will keep tracking the assignment.</p>
                        </div>
                    </div>

                    <div class="divide-y divide-base-300">
                        <section class="p-4 md:p-5" aria-labelledby="counter-target-title">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 id="counter-target-title" class="font-semibold">Target details</h3>
                                    <p class="mt-1 text-xs nexus-text-muted">Detected <span data-milcom-field="detected_at">{{ $relativeTime(data_get($selectedIncident, 'detected_at')) }}</span></p>
                                </div>
                                <a href="{{ data_get($selectedIncident, 'war_url', 'https://politicsandwar.com/nation/war/timeline/war='.data_get($selectedIncident, 'war_id', '')) }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm">
                                    War timeline
                                    <x-icon name="o-arrow-top-right-on-square" class="size-4" aria-hidden="true" />
                                </a>
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-base-300 bg-base-300">
                                <div class="bg-base-100 p-3"><dt class="nexus-stat-label">Score</dt><dd class="mt-1 font-semibold tabular-nums" data-milcom-field="score">{{ number_format((float) data_get($aggressor, 'score', 0), 0) }}</dd></div>
                                <div class="bg-base-100 p-3"><dt class="nexus-stat-label">Cities</dt><dd class="mt-1 font-semibold tabular-nums" data-milcom-field="cities">{{ number_format((int) data_get($aggressor, 'num_cities', data_get($aggressor, 'cities', 0))) }}</dd></div>
                            </dl>
                            <x-milcom.military-summary :nation="$aggressor" variant="tiles" dynamic class="mt-3" label="Counter target military" />
                        </section>

                        <section class="p-4 md:p-5" aria-labelledby="recommended-counter-team-title">
                            <div class="flex items-end justify-between gap-3">
                                <div>
                                    <h3 id="recommended-counter-team-title" class="font-semibold">Recommended team</h3>
                                    <p class="mt-1 text-xs nexus-text-muted">The team balances matchup quality and military coverage.</p>
                                </div>
                                <span class="text-sm font-semibold tabular-nums"><span data-milcom-field="team_depth">{{ $recommendedTeam->count() }}</span>/3</span>
                            </div>
                            <div class="mt-4 divide-y divide-base-300 border-y border-base-300" data-milcom-list="team">
                                @forelse ($recommendedTeam as $member)
                                    @php
                                        $memberData = data_get($member, 'friendly', data_get($member, 'nation', $member));
                                    @endphp
                                    <article class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 py-3">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h4 class="truncate font-semibold">
                                                    <x-pw-nation-link
                                                        :nation-id="data_get($memberData, 'id', data_get($member, 'friendly_nation_id'))"
                                                        :label="data_get($memberData, 'nation_name', data_get($member, 'nation_name', 'Unknown nation'))"
                                                    />
                                                </h4>
                                                @if ((bool) data_get($member, 'discord_linked', data_get($memberData, 'discord_linked', false)))
                                                    <span class="badge badge-success badge-soft">Discord linked</span>
                                                @else
                                                    <span class="badge badge-warning badge-soft">No Discord link</span>
                                                @endif
                                            </div>
                                            <p class="mt-1 text-xs nexus-text-muted">{{ data_get($member, 'offensive_wars', 0) }} active + {{ data_get($member, 'reserved_slots', 0) }} reserved of {{ data_get($member, 'offensive_capacity', '?') }} slots</p>
                                            <x-milcom.military-summary :nation="$memberData" class="mt-2" label="Assigned nation military" />
                                        </div>
                                        <div class="text-right">
                                            <p class="font-semibold tabular-nums">{{ number_format((float) data_get($member, 'score', data_get($member, 'pair_score', 0)), 0) }}</p>
                                            <p class="text-xs nexus-text-muted">match score</p>
                                        </div>
                                    </article>
                                @empty
                                    <p class="py-5 text-center text-sm nexus-text-muted">{{ config('app.name') }} is still building the team, or no eligible team was found.</p>
                                @endforelse
                            </div>
                        </section>

                        <section class="p-4 md:p-5" aria-labelledby="counter-preflight-checks-title">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 id="counter-preflight-checks-title" class="font-semibold">Final checks</h3>
                                    <p class="mt-1 text-xs nexus-text-muted">{{ config('app.name') }} checks these again before creating the room.</p>
                                </div>
                                <span class="nexus-status {{ $hardBlockers->isEmpty() ? 'nexus-status--success' : 'nexus-status--error' }}">{{ $hardBlockers->isEmpty() ? 'Ready' : 'Blocked' }}</span>
                            </div>

                            <div class="mt-4 grid gap-2 sm:grid-cols-2" data-milcom-list="preflight">
                                @forelse ($preflight as $check)
                                    @php
                                        $checkStatus = (string) data_get($check, 'status', 'ready');
                                        $isReady = in_array($checkStatus, ['ready', 'pass', 'healthy'], true);
                                    @endphp
                                    <div class="flex items-start gap-2 rounded-md border border-base-300 p-3">
                                        <x-icon :name="$isReady ? 'o-check-circle' : ($checkStatus === 'warning' ? 'o-exclamation-triangle' : 'o-x-circle')" class="mt-0.5 size-4 shrink-0 {{ $isReady ? 'text-success' : ($checkStatus === 'warning' ? 'text-warning' : 'text-error') }}" aria-hidden="true" />
                                        <div>
                                            <p class="text-sm font-semibold">{{ data_get($check, 'label', 'Check') }}</p>
                                            <p class="mt-1 text-xs nexus-text-muted">{{ data_get($check, 'detail', str($checkStatus)->headline()) }}</p>
                                        </div>
                                    </div>
                                @empty
                                    @foreach ([
                                        ['label' => 'Team', 'detail' => 'Waiting for team data'],
                                        ['label' => 'Offensive slots', 'detail' => 'Checked again before the room is created'],
                                        ['label' => 'Data age', 'detail' => 'No team data loaded'],
                                        ['label' => 'Discord', 'detail' => 'Discord bot status is unavailable'],
                                    ] as $check)
                                        <div class="flex items-start gap-2 rounded-md border border-base-300 p-3">
                                            <x-icon name="o-clock" class="mt-0.5 size-4 shrink-0 nexus-text-muted" aria-hidden="true" />
                                            <div><p class="text-sm font-semibold">{{ $check['label'] }}</p><p class="mt-1 text-xs nexus-text-muted">{{ $check['detail'] }}</p></div>
                                        </div>
                                    @endforeach
                                @endforelse
                            </div>

                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <div>
                                    <h4 class="text-sm font-semibold">Blockers</h4>
                                    <ul class="mt-2 grid gap-2" data-milcom-list="blockers">
                                        @forelse ($blockers as $blocker)
                                            @php
                                                $blockerText = is_string($blocker) ? $blocker : data_get($blocker, 'message', data_get($blocker, 'label', 'Blocked'));
                                                $isHard = is_string($blocker) || (bool) data_get($blocker, 'hard', true);
                                            @endphp
                                            <li class="flex items-start gap-2 text-sm {{ $isHard ? 'text-error' : 'text-warning' }}">
                                                <x-icon :name="$isHard ? 'o-x-circle' : 'o-exclamation-triangle'" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                                <span>{{ $blockerText }}</span>
                                            </li>
                                        @empty
                                            <li class="flex items-start gap-2 text-sm text-success"><x-icon name="o-check-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" /><span>No blockers.</span></li>
                                        @endforelse
                                    </ul>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold">Warnings</h4>
                                    <ul class="mt-2 grid gap-2 text-sm text-warning" data-milcom-list="warnings">
                                        @forelse ($warnings as $warning)
                                            <li class="flex items-start gap-2"><x-icon name="o-exclamation-triangle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" /><span>{{ is_string($warning) ? $warning : data_get($warning, 'message', data_get($warning, 'label', 'Warning')) }}</span></li>
                                        @empty
                                            <li class="nexus-text-muted">No warnings.</li>
                                        @endforelse
                                    </ul>
                                </div>
                            </div>
                        </section>

                        <section class="p-4 md:p-5" aria-labelledby="alternative-counter-teams-title">
                            <h3 id="alternative-counter-teams-title" class="font-semibold">Alternative teams</h3>
                            <div class="mt-3 grid gap-3" data-milcom-list="alternatives">
                                @forelse ($alternativeTeams as $alternative)
                                    <article class="flex flex-col gap-3 rounded-md border border-base-300 p-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0 grow">
                                            <ul class="grid gap-2">
                                                @forelse (data_get($alternative, 'team', []) as $alternativeMember)
                                                    @php
                                                        $alternativeMemberData = data_get($alternativeMember, 'friendly', $alternativeMember);
                                                    @endphp
                                                    <li class="grid gap-1 sm:grid-cols-[minmax(8rem,1fr)_auto] sm:items-center sm:gap-4">
                                                        <span class="truncate font-semibold">
                                                            <x-pw-nation-link
                                                                :nation-id="data_get($alternativeMemberData, 'id')"
                                                                :label="data_get($alternativeMemberData, 'nation_name', 'Member')"
                                                            />
                                                        </span>
                                                        <x-milcom.military-summary :nation="$alternativeMemberData" class="sm:justify-end" label="Alternative nation military" />
                                                    </li>
                                                @empty
                                                    <li class="font-semibold">Alternative team</li>
                                                @endforelse
                                            </ul>
                                            <p class="mt-1 text-xs nexus-text-muted">Team score {{ number_format((float) data_get($alternative, 'team_score', data_get($alternative, 'score', 0)), 1) }}</p>
                                        </div>
                                        <button type="button" class="btn btn-outline btn-sm" data-milcom-use-alternative data-alternative-index="{{ $loop->index }}">Use team</button>
                                    </article>
                                @empty
                                    <p class="rounded-md border border-dashed border-base-300 p-4 text-sm nexus-text-muted">No other eligible team found.</p>
                                @endforelse
                            </div>
                        </section>

                        <section class="grid gap-5 p-4 md:grid-cols-2 md:p-5">
                            <div aria-labelledby="discord-room-preview-title">
                                <h3 id="discord-room-preview-title" class="font-semibold">Discord room preview</h3>
                                <div class="mt-3 rounded-md border border-base-300 bg-base-200/60 p-4" data-milcom-discord-preview>
                                    <div class="flex items-center gap-2 text-sm font-semibold">
                                        <x-icon name="o-chat-bubble-left-right" class="size-4 text-primary" aria-hidden="true" />
                                        <span data-milcom-field="room_name">counter-{{ str(data_get($aggressor, 'nation_name', 'target'))->slug() }}</span>
                                    </div>
                                    <p class="mt-3 text-sm leading-6 text-base-content/70">
                                        The room includes the target, assigned nations, war type, reason, priority, P&amp;W links, and forum tags.
                                    </p>
                                    <p class="mt-3 text-xs nexus-text-muted">One forum room · approved mentions only · no response buttons</p>
                                </div>
                            </div>
                            <div aria-labelledby="counter-timeline-title">
                                <h3 id="counter-timeline-title" class="font-semibold">Timeline</h3>
                                <ol class="mt-3 grid gap-3" data-milcom-list="timeline">
                                    @forelse ($timeline as $event)
                                        <li class="grid grid-cols-[auto_minmax(0,1fr)] gap-3 text-sm">
                                            <span class="mt-1.5 size-2 rounded-full {{ $loop->first ? 'bg-primary' : 'bg-base-content/25' }}" aria-hidden="true"></span>
                                            <div>
                                                <p class="font-semibold">{{ data_get($event, 'title', str((string) data_get($event, 'type', 'event'))->headline()) }}</p>
                                                <p class="mt-0.5 text-xs nexus-text-muted">{{ $relativeTime(data_get($event, 'created_at', data_get($event, 'occurred_at'))) }}</p>
                                            </div>
                                        </li>
                                    @empty
                                        <li class="text-sm nexus-text-muted">Events appear here as they happen.</li>
                                    @endforelse
                                </ol>
                            </div>
                        </section>
                    </div>

                    <div class="sticky bottom-0 grid gap-3 border-t border-base-300 bg-base-100/95 p-4 backdrop-blur md:p-5">
                        <form
                            method="POST"
                            action="{{ $apiBase }}/objectives/{{ $objectiveId }}/dispatch/retry"
                            class="{{ $dispatchFailed ? 'flex' : 'hidden' }} flex-col gap-3 rounded-md border border-error/30 bg-error/5 p-3 sm:flex-row sm:items-center sm:justify-between"
                            data-milcom-command="retry-dispatch"
                            data-milcom-retry-dispatch
                        >
                            @csrf
                            <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                            <div><p class="text-sm font-semibold">Discord room failed</p><p class="mt-1 text-xs nexus-text-muted" data-milcom-field="dispatch_error">{{ data_get($selectedObjective, 'dispatch.error', 'You can safely retry this room.') }}</p></div>
                            <button type="submit" class="btn btn-error btn-outline btn-sm sm:shrink-0">Retry Discord room</button>
                        </form>
                        <form
                            method="POST"
                            action="{{ $apiBase }}/objectives/{{ $objectiveId }}/dispatch"
                            class="{{ $canDispatch ? '' : 'hidden' }}"
                            data-milcom-command="approve-dispatch-counter"
                        >
                            @csrf
                            <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                            <label class="mb-3 {{ $warnings->isNotEmpty() ? 'block' : 'hidden' }}" data-milcom-override-field>
                                    <span class="label px-0">Reason for ignoring warnings</span>
                                    <textarea name="override_reason" class="textarea min-h-20 w-full" placeholder="Explain why this counter should be sent despite the warnings" @required($warnings->isNotEmpty())></textarea>
                            </label>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs nexus-text-muted">Approving reserves the slots and starts the Discord room.</p>
                                <button type="submit" class="btn btn-primary sm:shrink-0" @disabled($hardBlockers->isNotEmpty() || ! $selectedObjective)>
                                    <x-icon name="o-bolt" class="size-5" aria-hidden="true" />
                                    Approve and create room
                                </button>
                            </div>
                        </form>
                        <div class="{{ ! $canDispatch && ! $dispatchFailed && $selectedObjective ? 'flex' : 'hidden' }} items-center justify-between gap-3 rounded-md border border-success/25 bg-success/5 p-3" data-milcom-dispatch-state>
                            <div><p class="text-sm font-semibold">Counter sent</p><p class="mt-1 text-xs nexus-text-muted">{{ config('app.name') }} reserved the slots and is tracking the Discord room.</p></div>
                            <span class="nexus-status nexus-status--success" data-milcom-field="dispatch_status">{{ str($dispatchStatus ?: $objectiveStatus)->headline() }}</span>
                        </div>
                        <form method="POST" action="{{ $apiBase }}/objectives/{{ $objectiveId }}/cancel" class="hidden items-end gap-2 md:flex" data-milcom-command="cancel-objective" data-confirm="Ignore this war and cancel its counter?">
                            @csrf
                            <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                            <label class="block min-w-0 flex-1"><span class="label px-0 text-xs">Reason</span><input type="text" minlength="10" maxlength="1000" name="reason" class="input input-sm w-full" placeholder="Why no counter is needed" required></label>
                            <button type="submit" class="btn btn-ghost btn-sm text-error" @disabled(! $selectedObjective)>Ignore war</button>
                        </form>
                    </div>
                </div>
            </section>
        </div>

        <div class="hidden alert alert-error items-start" role="alert" data-milcom-feedback>
            <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <div class="font-semibold" data-milcom-feedback-title>Could not update this counter</div>
                <p class="text-sm" data-milcom-feedback-message>The war is still open. No slots were reserved.</p>
            </div>
        </div>
        <p class="sr-only" role="status" aria-live="polite" data-milcom-status></p>
    </div>

    @include('admin.milcom.partials.scripts')
@endsection

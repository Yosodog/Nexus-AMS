@extends('layouts.admin')

@php
    $operation = $operation ?? $plan ?? [];
    $operationId = (int) data_get($operation, 'id', 0);
    $operationName = (string) data_get($operation, 'name', 'Coalition Dawn');
    $operationStatus = (string) data_get($operation, 'status.value', data_get($operation, 'status', 'review'));
    $storedStage = (string) data_get($operation, 'current_stage', 'staffing');
    $generationVersion = (int) data_get($operation, 'generation_version', 1);
    $apiBase = $apiBase ?? url('/api/v1/milcom');
    $objectives = $objectives ?? collect();
    $objectiveRows = collect(is_object($objectives) && method_exists($objectives, 'items') ? $objectives->items() : $objectives)->take(50);
    $selectedObjective = $selectedObjective ?? $objectiveRows->first();
    $selectedRecommendation = $selectedRecommendation ?? data_get($selectedObjective, 'recommendation', []);
    $selectedTeam = collect($selectedTeam ?? data_get($selectedRecommendation, 'proposed_team', data_get($selectedObjective, 'assignments', [])))->take(3);
    $selectedAlternatives = collect($selectedAlternatives ?? data_get($selectedRecommendation, 'alternatives', []))->take(3);
    $selectedBlockers = collect($selectedBlockers ?? data_get($selectedRecommendation, 'blockers', data_get($selectedObjective, 'blockers', [])));
    $selectedWarnings = collect($selectedWarnings ?? data_get($selectedRecommendation, 'warnings', data_get($selectedObjective, 'warnings', [])));
    $selectedReasons = collect($selectedReasons ?? data_get($selectedRecommendation, 'explanations', data_get($selectedObjective, 'reasons', [])))->take(8);
    $summary = $summary ?? data_get($operation, 'summary', []);
    $waves = collect($waves ?? [$operation]);
    $waveNumber = (int) data_get($operation, 'metadata.wave', 1);
    $isActive = $operationStatus === 'active';
    $isTerminal = in_array($operationStatus, ['completed', 'archived'], true);
    $hasTargets = (int) data_get($summary, 'objective_count', 0) > 0;
    $autoHoldOnFinalize = (int) data_get($summary, 'auto_hold_on_finalize', 0);
    $finalizeReviewRequired = (int) data_get($summary, 'finalize_review_required', 0);
    $approvedOrLiveTargets = (int) data_get($summary, 'approved', 0)
        + (int) data_get($summary, 'waiting_to_declare', 0)
        + (int) data_get($summary, 'engaged', 0);
    $canFinalizeOperation = ! $isActive
        && ! $isTerminal
        && $finalizeReviewRequired === 0
        && $approvedOrLiveTargets > 0;
    $finalizeDisabledReason = $approvedOrLiveTargets === 0
        ? 'Approve at least one target before finalizing this wave.'
        : $finalizeReviewRequired.' '.str('target')->plural($finalizeReviewRequired).' with proposed teams still need review.';
    $finalizeConfirmation = $autoHoldOnFinalize > 0
        ? 'Finalize this wave and open the live dashboard? '
            .($autoHoldOnFinalize === 1
                ? '1 target without a team will'
                : $autoHoldOnFinalize.' targets without teams will')
            .' be moved to Hold automatically.'
        : 'Finalize this wave and open the live dashboard? Targets and approved teams will be locked in for delivery.';
    $filters = $filters ?? request()->only(['filter', 'search', 'tier']);
    $stats = $stats ?? [];
    $activeFilter = (string) data_get($filters, 'filter', 'needs_attention');
    $stages = [
        'scope' => 'Setup',
        'objectives' => 'Targets',
        'staffing' => 'Assign teams',
        'dispatch' => 'Finalize',
        'live' => 'Live dashboard',
    ];
    $requestedStage = (string) request()->query('stage', '');
    $isStatsView = $requestedStage === 'stats';
    $currentStage = ! $isStatsView && array_key_exists($requestedStage, $stages) ? $requestedStage : $storedStage;
    $stageKeys = array_keys($stages);
    $currentStageIndex = max(0, array_search($currentStage, $stageKeys, true) ?: 0);
    $relativeTime = static function ($value): string {
        if (is_object($value) && method_exists($value, 'diffForHumans')) {
            return $value->diffForHumans();
        }

        return filled($value) ? (string) $value : 'Unknown';
    };
    $statusTone = static fn (string $status): string => match ($status) {
        'approved', 'dispatched', 'engaged', 'completed', 'active' => 'nexus-status--success',
        'blocked', 'failed', 'conflict' => 'nexus-status--error',
        'dispatching', 'generating' => 'nexus-status--info',
        'review', 'pending' => 'nexus-status--warning',
        default => 'nexus-status--neutral',
    };
    $statusLabel = static fn (string $status): string => match ($status) {
        'dispatching' => 'Creating Discord rooms',
        'dispatched' => 'Sent to Discord',
        'engaged' => 'War started',
        'generating' => 'Building teams',
        'pending' => 'Waiting for review',
        'review' => 'Ready for review',
        default => str($status)->headline()->toString(),
    };
    $scopeAlliances = collect(data_get($operation, 'alliances', []));
    $scopeNations = collect(data_get($operation, 'nations', []));
    $scopeAllianceDetails = static function ($scopeAlliance): array {
        $alliance = data_get($scopeAlliance, 'alliance');
        $allianceId = (int) data_get($scopeAlliance, 'alliance_id');

        return [
            'id' => $allianceId,
            'name' => (string) data_get($alliance, 'name', 'Alliance #'.$allianceId),
            'acronym' => data_get($alliance, 'acronym'),
            'flag' => data_get($alliance, 'flag'),
            'rank' => data_get($alliance, 'rank'),
            'score' => data_get($alliance, 'score'),
            'average_score' => data_get($alliance, 'average_score'),
            'member_count' => null,
            'url' => 'https://politicsandwar.com/alliance/id='.$allianceId,
        ];
    };
    $friendlyAlliances = $scopeAlliances
        ->where('role', 'friendly')
        ->where('included', true)
        ->map($scopeAllianceDetails)
        ->values();
    $enemyAlliances = $scopeAlliances
        ->where('role', 'enemy')
        ->where('included', true)
        ->map($scopeAllianceDetails)
        ->values();
    $friendlyAllianceCsv = $scopeAlliances->where('role', 'friendly')->where('included', true)->pluck('alliance_id')->implode(', ');
    $enemyAllianceCsv = $scopeAlliances->where('role', 'enemy')->where('included', true)->pluck('alliance_id')->implode(', ');
    $includedFriendlyCsv = $scopeNations->where('role', 'friendly')->where('included', true)->pluck('nation_id')->implode(', ');
    $excludedFriendlyCsv = $scopeNations->where('role', 'friendly')->where('included', false)->pluck('nation_id')->implode(', ');
    $includedTargetCsv = $scopeNations->where('role', 'target')->where('included', true)->pluck('nation_id')->implode(', ');
    $excludedTargetCsv = $scopeNations->where('role', 'target')->where('included', false)->pluck('nation_id')->implode(', ');
@endphp

@section('title', $operationName.' | Milcom')

@section('content')
    <div
        data-milcom-app="{{ $isStatsView ? 'plan-stats' : 'plan-workspace' }}"
        data-api-base="{{ $apiBase }}"
        data-operation-id="{{ $operationId }}"
        data-operation-status="{{ $operationStatus }}"
        data-generation-version="{{ $generationVersion }}"
        data-objectives-endpoint="{{ $apiBase }}/operations/{{ $operationId }}/objectives?filter={{ urlencode($activeFilter) }}&limit=50"
        data-objective-detail-template="{{ $apiBase }}/objectives/{id}"
        class="contents"
    >
        <x-header :title="$operationName" separator use-h1>
            <x-slot:subtitle>
                <span class="inline-flex flex-wrap items-center gap-2">
                    <span class="nexus-status {{ $statusTone($operationStatus) }}">{{ $statusLabel($operationStatus) }}</span>
                    <span>Plan version {{ number_format($generationVersion) }}</span>
                    <span aria-hidden="true">·</span>
                    <span>Wave {{ number_format($waveNumber) }}</span>
                    <span aria-hidden="true">·</span>
                    <span>Team scoring: fixed rules</span>
                </span>
            </x-slot:subtitle>
            <x-slot:actions>
                <div class="hidden flex-wrap gap-2 md:flex">
                    <form
                        method="POST"
                        action="{{ $apiBase }}/operations/{{ $operationId }}/clone"
                        data-milcom-command="clone-operation"
                        data-success-redirect
                    >
                        @csrf
                        <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                        <button type="submit" class="btn btn-outline">{{ $isActive ? 'New wave' : 'Clone wave' }}</button>
                    </form>
                    @if (! $isActive && ! $isTerminal)
                        <form
                            method="POST"
                            action="{{ $apiBase }}/operations/{{ $operationId }}/recommendations"
                            data-milcom-command="recommend"
                        >
                            @csrf
                            <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                            <button type="submit" class="btn btn-outline">
                                <x-icon name="o-arrow-path" class="size-5" aria-hidden="true" />
                                Auto-assign teams
                            </button>
                        </form>
                    @endif
                    @if ($canFinalizeOperation)
                        <form
                            method="POST"
                            action="{{ $apiBase }}/operations/{{ $operationId }}/activate"
                            data-milcom-command="activate-operation"
                            data-success-redirect
                            data-confirm="{{ $finalizeConfirmation }}"
                            data-confirm-title="Finalize this wave?"
                            data-confirm-label="Finalize wave"
                        >
                            @csrf
                            <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                            <button type="submit" class="btn btn-primary">Finalize wave</button>
                        </form>
                    @elseif (! $isActive && $hasTargets && ! $isTerminal)
                        <button type="button" class="btn btn-primary" disabled title="{{ $finalizeDisabledReason }}">Finalize wave</button>
                    @elseif ($isActive)
                        <form
                            method="POST"
                            action="{{ $apiBase }}/operations/{{ $operationId }}/complete"
                            data-milcom-command="complete-operation"
                            data-success-redirect
                            data-confirm="End this operation? This marks every open target finished, releases unused offensive slots, and closes its Discord rooms. Only do this after the war is over."
                        >
                            @csrf
                            <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                            <button type="submit" class="btn btn-outline">End operation</button>
                        </form>
                    @endif
                </div>
            </x-slot:actions>
        </x-header>

        @include('admin.milcom.partials.navigation', ['milcomCurrent' => 'plans'])

        @if ($waves->count() > 1)
            <nav class="flex flex-wrap items-center gap-2 rounded-md border border-base-300 bg-base-100 p-2" aria-label="Operation waves">
                <span class="px-2 text-xs font-semibold text-base-content/65">Waves</span>
                @foreach ($waves as $wave)
                    @php $listedWaveNumber = (int) data_get($wave, 'metadata.wave', 1); @endphp
                    <a
                        href="{{ route('admin.milcom.plans.show', $wave) }}"
                        class="btn btn-sm {{ (int) data_get($wave, 'id') === $operationId ? 'btn-primary' : 'btn-ghost' }}"
                        @if ((int) data_get($wave, 'id') === $operationId) aria-current="page" @endif
                    >Wave {{ $listedWaveNumber }}</a>
                @endforeach
            </nav>
        @endif

        <nav class="tabs tabs-border w-fit" role="tablist" aria-label="Plan view">
            <a
                href="{{ $isTerminal ? route('admin.milcom.archive.show', $operationId) : route('admin.milcom.plans.show', ['operation' => $operationId, 'stage' => $isActive ? 'live' : $storedStage]) }}"
                role="tab"
                class="tab gap-2 {{ ! $isStatsView ? 'tab-active' : '' }}"
                aria-selected="{{ ! $isStatsView ? 'true' : 'false' }}"
            >
                <x-icon name="o-clipboard-document-list" class="size-4" aria-hidden="true" />
                Plan
            </a>
            <a
                href="{{ route('admin.milcom.plans.show', ['operation' => $operationId, 'stage' => 'stats']) }}"
                role="tab"
                class="tab gap-2 {{ $isStatsView ? 'tab-active' : '' }}"
                aria-selected="{{ $isStatsView ? 'true' : 'false' }}"
                @if ($isStatsView) aria-current="page" @endif
            >
                <x-icon name="o-chart-bar" class="size-4" aria-hidden="true" />
                Stats
            </a>
        </nav>

        @if ($isStatsView)
            @include('admin.milcom.plans._stats', ['stats' => $stats])
        @else

        @if ($isActive)
            <section class="alert alert-success items-start" aria-labelledby="live-operation-title">
                <x-icon name="o-signal" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <h2 id="live-operation-title" class="font-semibold">This operation is live</h2>
                    <p class="mt-1 max-w-3xl text-sm">Milcom will keep matching declarations and war results to this plan. The targets and assigned nations remain available below.</p>
                    <nav class="mt-3 flex flex-wrap gap-2" aria-label="Live plan views">
                        <a href="{{ route('admin.milcom.plans.show', ['operation' => $operationId, 'filter' => 'all']) }}" class="btn btn-success btn-sm">All targets</a>
                        <a href="{{ route('admin.milcom.plans.show', ['operation' => $operationId, 'filter' => 'needs_attention']) }}" class="btn btn-ghost btn-sm">Needs attention</a>
                        <a href="{{ route('admin.milcom.plans.show', ['operation' => $operationId, 'filter' => 'dispatched']) }}" class="btn btn-ghost btn-sm">Waiting to declare</a>
                        <a href="{{ route('admin.milcom.plans.show', ['operation' => $operationId, 'filter' => 'engaged']) }}" class="btn btn-ghost btn-sm">Wars started</a>
                    </nav>
                </div>
            </section>

            <section class="nexus-panel" aria-labelledby="wave-delivery-title">
                <div class="nexus-panel__header items-start">
                    <div>
                        <h2 id="wave-delivery-title" class="nexus-section-title">Send this wave</h2>
                        <p class="mt-1 max-w-3xl text-sm text-base-content/65">Create one Discord room per target, send each member their target and team in-game, or download the complete assignment list.</p>
                    </div>
                    @if ((int) data_get($summary, 'in_game_failed', 0) > 0)
                        <span class="badge badge-error badge-soft">{{ number_format((int) data_get($summary, 'in_game_failed')) }} messages failed</span>
                    @endif
                </div>
                <div class="grid gap-3 p-4 md:grid-cols-3 md:p-5">
                    <form
                        method="POST"
                        action="{{ $apiBase }}/operations/{{ $operationId }}/dispatch-ready"
                        data-milcom-command="dispatch-ready"
                        data-confirm="Create Discord rooms for every approved target that is still waiting for one?"
                    >
                        @csrf
                        <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                        <button type="submit" class="btn btn-primary w-full">
                            <x-icon name="o-chat-bubble-left-right" class="size-5" aria-hidden="true" />
                            Create remaining rooms
                        </button>
                        <p class="mt-2 text-xs leading-5 nexus-text-muted">{{ number_format((int) data_get($summary, 'approved', 0)) }} approved {{ \Illuminate\Support\Str::plural('target', (int) data_get($summary, 'approved', 0)) }} waiting.</p>
                    </form>

                    <form
                        method="POST"
                        action="{{ $apiBase }}/operations/{{ $operationId }}/deliver-in-game"
                        data-milcom-command="deliver-in-game"
                        data-confirm="Send each assigned nation its target and team through Politics & War? Messages already sent will not be repeated."
                    >
                        @csrf
                        <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                        <button type="submit" class="btn btn-outline w-full">
                            <x-icon name="o-paper-airplane" class="size-5" aria-hidden="true" />
                            Send targets in-game
                        </button>
                        <p class="mt-2 text-xs leading-5 nexus-text-muted">{{ number_format((int) data_get($summary, 'in_game_sent', 0)) }} sent · {{ number_format((int) data_get($summary, 'in_game_pending', 0)) }} queued.</p>
                    </form>

                    <div>
                        <a href="{{ route('admin.milcom.plans.export', $operation) }}" class="btn btn-outline w-full">
                            <x-icon name="o-arrow-down-tray" class="size-5" aria-hidden="true" />
                            Export target list
                        </a>
                        <p class="mt-2 text-xs leading-5 nexus-text-muted">CSV includes targets, assigned nations, statuses, match scores, and declarations.</p>
                    </div>
                </div>
            </section>
        @endif

        @php
            $recommendationStatus = (string) data_get($selectedRecommendation, 'status', '');
            $recommendationActive = in_array($recommendationStatus, ['queued', 'running'], true);
        @endphp
        <div class="{{ $recommendationActive ? 'flex' : 'hidden' }} alert alert-info items-center gap-3" role="status" data-milcom-recommendation-progress>
            <x-icon name="o-arrow-path" class="size-5 shrink-0 animate-spin motion-reduce:animate-none" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-3 text-sm"><span class="font-semibold" data-milcom-progress-label>{{ $recommendationActive ? 'Building teams' : 'Team progress' }}</span><span class="tabular-nums" data-milcom-progress-value>{{ (int) data_get($selectedRecommendation, 'progress_percent', 0) }}%</span></div>
                <progress class="progress progress-info mt-2 h-1.5 w-full" value="{{ (int) data_get($selectedRecommendation, 'progress_percent', 0) }}" max="100" data-milcom-progress-bar></progress>
                <p class="mt-2 hidden text-xs" data-milcom-progress-note></p>
            </div>
        </div>

        @php
            $operationFailure = data_get($operation, 'failure_details.message');
        @endphp
        <div class="{{ $operationFailure ? 'flex' : 'hidden' }} alert alert-error items-start" role="alert" data-milcom-feedback>
            <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <div class="font-semibold" data-milcom-feedback-title>{{ $operationFailure ? 'Could not build teams' : 'Action failed' }}</div>
                <p class="text-sm" data-milcom-feedback-message>{{ $operationFailure ?: 'Your current plan is still available.' }}</p>
            </div>
        </div>

        <div class="hidden alert alert-success items-start" role="status" data-milcom-result>
            <x-icon name="o-check-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p class="text-sm" data-milcom-result-message></p>
        </div>

        <nav class="overflow-x-auto" aria-label="Plan steps">
            <ol class="flex min-w-max items-center rounded-lg border border-base-300 bg-base-100 p-1">
                @foreach ($stages as $stageKey => $stageLabel)
                    @php
                        $stageIndex = array_search($stageKey, $stageKeys, true);
                        $isCurrentStage = $stageKey === $currentStage;
                        $isCompleteStage = $stageIndex < $currentStageIndex;
                    @endphp
                    <li class="flex items-center">
                        <a
                            href="{{ url('/admin/milcom/plans/'.$operationId.'?stage='.$stageKey) }}"
                            class="flex min-h-10 items-center gap-2 rounded-md px-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $isCurrentStage ? 'bg-primary text-primary-content' : ($isCompleteStage ? 'text-success hover:bg-success/10' : 'nexus-text-muted hover:bg-base-200') }}"
                            @if ($isCurrentStage) aria-current="step" @endif
                        >
                            <span class="inline-grid size-5 place-items-center rounded-full border {{ $isCurrentStage ? 'border-primary-content/50' : ($isCompleteStage ? 'border-success/40 bg-success/10' : 'border-base-content/25') }} text-[0.6875rem] tabular-nums">
                                @if ($isCompleteStage)
                                    <x-icon name="o-check" class="size-3.5" aria-hidden="true" />
                                @else
                                    {{ $stageIndex + 1 }}
                                @endif
                            </span>
                            {{ $stageLabel }}
                        </a>
                        @if (! $loop->last)
                            <x-icon name="o-chevron-right" class="mx-0.5 size-4 text-base-content/25" aria-hidden="true" />
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>

        <div class="alert alert-info items-start md:hidden" role="note">
            <x-icon name="o-device-phone-mobile" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <div class="font-semibold">Mobile view</div>
                <p class="text-sm">Use a tablet or computer to edit teams, approve targets, or change the plan setup.</p>
            </div>
        </div>

        @if (! $isActive && ($currentStage === 'scope' || (int) data_get($summary, 'objective_count', 0) === 0))
            <section class="nexus-panel" aria-labelledby="operation-scope-title">
                <div class="nexus-panel__header">
                    <div>
                        <h2 id="operation-scope-title" class="nexus-section-title">Choose alliances and targets</h2>
                        <p class="mt-1 text-sm text-base-content/65">Search for alliances on each side. You can still enter IDs manually or add nation-level exceptions.</p>
                    </div>
                    <span class="badge badge-warning badge-soft">Do this first</span>
                </div>

                <form
                    method="PUT"
                    action="{{ $apiBase }}/operations/{{ $operationId }}/scope"
                    class="grid gap-5 p-4 md:grid-cols-2 md:p-5"
                    data-milcom-command="commit-scope"
                    data-success-redirect
                >
                    @csrf
                    <input type="hidden" name="generation_version" value="{{ $generationVersion }}">

                    <div class="grid gap-4 md:col-span-2 lg:grid-cols-2">
                        <section
                            class="rounded-lg bg-success/5 p-4 ring-1 ring-success/20"
                            data-alliance-picker
                            data-alliance-side="friendly"
                            aria-labelledby="friendly-alliance-picker-title"
                        >
                            <script type="application/json" data-alliance-picker-initial>{!! json_encode($friendlyAlliances, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-grid size-8 shrink-0 place-items-center rounded-md bg-success/15 text-success" aria-hidden="true">
                                            <x-icon name="o-shield-check" class="size-5" />
                                        </span>
                                        <div>
                                            <h3 id="friendly-alliance-picker-title" class="font-semibold">Your side</h3>
                                            <p class="text-xs nexus-text-muted">Nations available for teams</p>
                                        </div>
                                    </div>
                                </div>
                                <span class="badge badge-success badge-soft tabular-nums" data-alliance-count>0</span>
                            </div>

                            <div class="mt-4 grid gap-2" data-alliance-selected aria-live="polite"></div>
                            <p class="mt-4 hidden rounded-md border border-dashed border-base-300 px-3 py-4 text-center text-sm nexus-text-muted" data-alliance-empty>No friendly alliances added yet.</p>

                            <div class="relative mt-4">
                                <label for="friendly-alliance-search" class="mb-1.5 block text-sm font-semibold">Add an alliance</label>
                                <div class="input flex w-full items-center gap-2 focus-within:outline-2 focus-within:outline-primary">
                                    <x-icon name="o-magnifying-glass" class="size-4 shrink-0 nexus-text-muted" aria-hidden="true" />
                                    <input
                                        id="friendly-alliance-search"
                                        type="search"
                                        class="min-w-0 grow bg-transparent outline-none"
                                        placeholder="Name, acronym, or ID"
                                        autocomplete="off"
                                        role="combobox"
                                        aria-autocomplete="list"
                                        aria-expanded="false"
                                        aria-controls="friendly-alliance-results"
                                        data-alliance-search
                                    >
                                    <span class="loading loading-spinner loading-xs hidden" data-alliance-loading aria-label="Searching"></span>
                                </div>
                                <div
                                    id="friendly-alliance-results"
                                    class="absolute inset-x-0 top-full z-[var(--nexus-z-dropdown)] mt-1 hidden max-h-80 overflow-y-auto rounded-lg border border-base-300 bg-base-100 p-1 shadow-xl"
                                    role="listbox"
                                    data-alliance-results
                                ></div>
                            </div>
                        </section>

                        <section
                            class="rounded-lg bg-error/5 p-4 ring-1 ring-error/20"
                            data-alliance-picker
                            data-alliance-side="enemy"
                            aria-labelledby="enemy-alliance-picker-title"
                        >
                            <script type="application/json" data-alliance-picker-initial>{!! json_encode($enemyAlliances, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <span class="inline-grid size-8 shrink-0 place-items-center rounded-md bg-error/15 text-error" aria-hidden="true">
                                        <x-icon name="o-flag" class="size-5" />
                                    </span>
                                    <div>
                                        <h3 id="enemy-alliance-picker-title" class="font-semibold">Enemy side</h3>
                                        <p class="text-xs nexus-text-muted">Nations that become targets</p>
                                    </div>
                                </div>
                                <span class="badge badge-error badge-soft tabular-nums" data-alliance-count>0</span>
                            </div>

                            <div class="mt-4 grid gap-2" data-alliance-selected aria-live="polite"></div>
                            <p class="mt-4 hidden rounded-md border border-dashed border-base-300 px-3 py-4 text-center text-sm nexus-text-muted" data-alliance-empty>No enemy alliances added yet.</p>

                            <div class="relative mt-4">
                                <label for="enemy-alliance-search" class="mb-1.5 block text-sm font-semibold">Add an alliance</label>
                                <div class="input flex w-full items-center gap-2 focus-within:outline-2 focus-within:outline-primary">
                                    <x-icon name="o-magnifying-glass" class="size-4 shrink-0 nexus-text-muted" aria-hidden="true" />
                                    <input
                                        id="enemy-alliance-search"
                                        type="search"
                                        class="min-w-0 grow bg-transparent outline-none"
                                        placeholder="Name, acronym, or ID"
                                        autocomplete="off"
                                        role="combobox"
                                        aria-autocomplete="list"
                                        aria-expanded="false"
                                        aria-controls="enemy-alliance-results"
                                        data-alliance-search
                                    >
                                    <span class="loading loading-spinner loading-xs hidden" data-alliance-loading aria-label="Searching"></span>
                                </div>
                                <div
                                    id="enemy-alliance-results"
                                    class="absolute inset-x-0 top-full z-[var(--nexus-z-dropdown)] mt-1 hidden max-h-80 overflow-y-auto rounded-lg border border-base-300 bg-base-100 p-1 shadow-xl"
                                    role="listbox"
                                    data-alliance-results
                                ></div>
                            </div>
                        </section>
                    </div>

                    <details class="rounded-lg border border-base-300 bg-base-200/35 md:col-span-2">
                        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold hover:bg-base-200/60">Enter alliance IDs manually</summary>
                        <div class="grid gap-4 border-t border-base-300 p-4 md:grid-cols-2">
                            <label class="block">
                                <span class="label px-0">Friendly alliance IDs</span>
                                <textarea name="friendly_alliance_ids_csv" rows="3" class="textarea w-full" placeholder="1234, 5678" data-alliance-manual="friendly">{{ $friendlyAllianceCsv }}</textarea>
                                <span class="mt-1 block text-xs nexus-text-muted">Separate IDs with commas or spaces. Applicants are skipped.</span>
                            </label>

                            <label class="block">
                                <span class="label px-0">Enemy alliance IDs</span>
                                <textarea name="enemy_alliance_ids_csv" rows="3" class="textarea w-full" placeholder="9876, 5432" data-alliance-manual="enemy">{{ $enemyAllianceCsv }}</textarea>
                                <span class="mt-1 block text-xs nexus-text-muted">Each eligible nation becomes a target.</span>
                            </label>
                        </div>
                    </details>

                    <label class="block">
                        <span class="label px-0">Include friendly nation IDs</span>
                        <textarea name="included_friendly_nation_ids_csv" rows="2" class="textarea w-full" placeholder="Optional">{{ $includedFriendlyCsv }}</textarea>
                    </label>

                    <label class="block">
                        <span class="label px-0">Exclude friendly nation IDs</span>
                        <textarea name="excluded_friendly_nation_ids_csv" rows="2" class="textarea w-full" placeholder="Optional">{{ $excludedFriendlyCsv }}</textarea>
                    </label>

                    <label class="block">
                        <span class="label px-0">Include target nation IDs</span>
                        <textarea name="included_target_nation_ids_csv" rows="2" class="textarea w-full" placeholder="Optional">{{ $includedTargetCsv }}</textarea>
                    </label>

                    <label class="block">
                        <span class="label px-0">Exclude target nation IDs</span>
                        <textarea name="excluded_target_nation_ids_csv" rows="2" class="textarea w-full" placeholder="Optional">{{ $excludedTargetCsv }}</textarea>
                    </label>

                    <div class="alert alert-warning items-start md:col-span-2" role="note">
                        <x-icon name="o-lock-closed" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        <p class="text-sm">You cannot change these alliances or nations after the first Discord room is created. Clone this wave if you need a different target list.</p>
                    </div>

                    <div class="flex justify-end md:col-span-2">
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="o-check" class="size-5" aria-hidden="true" />
                            Save and build targets
                        </button>
                    </div>
                </form>
            </section>
        @endif

        <section class="nexus-metrics" aria-label="{{ $isActive ? 'Live operation status' : 'Plan coverage' }}">
            @if ($isActive)
                <div class="nexus-metric {{ (int) data_get($summary, 'live_alerts', 0) > 0 ? 'bg-warning/5' : '' }}">
                    <span class="nexus-stat-label">Live alerts</span>
                    <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'live_alerts', 0)) }}</strong>
                    <span class="nexus-stat-helper">{{ number_format((int) data_get($summary, 'late_first_hits', 0)) }} waiting on a first hit</span>
                </div>
                <div class="nexus-metric">
                    <span class="nexus-stat-label">Rooms to create</span>
                    <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'approved', 0)) }}</strong>
                    <span class="nexus-stat-helper">Approved targets not sent</span>
                </div>
                <div class="nexus-metric">
                    <span class="nexus-stat-label">Waiting to declare</span>
                    <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'waiting_to_declare', 0)) }}</strong>
                    <span class="nexus-stat-helper">Discord rooms sent</span>
                </div>
                <div class="nexus-metric">
                    <span class="nexus-stat-label">Wars started</span>
                    <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'engaged', 0)) }}</strong>
                    <span class="nexus-stat-helper">Declarations matched</span>
                </div>
                <div class="nexus-metric">
                    <span class="nexus-stat-label">Finished</span>
                    <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'finished', 0)) }}</strong>
                    <span class="nexus-stat-helper">Completed, cancelled, or expired</span>
                </div>
            @else
                <div class="nexus-metric {{ (int) data_get($summary, 'critical_gaps', 0) > 0 ? 'bg-error/5' : '' }}">
                    <span class="nexus-stat-label">Critical coverage</span>
                    <strong class="nexus-stat-value">{{ number_format((float) data_get($summary, 'critical_minimum_coverage_percent', 0), 0) }}%</strong>
                    <span class="nexus-stat-helper">{{ number_format((int) data_get($summary, 'critical_gaps', 0)) }} gaps</span>
                </div>
                <div class="nexus-metric">
                    <span class="nexus-stat-label">Team depth</span>
                    <strong class="nexus-stat-value">{{ number_format((float) data_get($summary, 'desired_depth_percent', 0), 0) }}%</strong>
                    <span class="nexus-stat-helper">After required coverage</span>
                </div>
                <div class="nexus-metric">
                    <span class="nexus-stat-label">Member load</span>
                    <strong class="nexus-stat-value">{{ number_format((float) data_get($summary, 'member_utilization_percent', 0), 0) }}%</strong>
                    <span class="nexus-stat-helper">Proposed and approved team spots</span>
                </div>
                <div class="nexus-metric">
                    <span class="nexus-stat-label">Conflicts</span>
                    <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'conflicts', 0)) }}</strong>
                    <span class="nexus-stat-helper">Slot or declaration changes</span>
                </div>
                <div class="nexus-metric">
                    <span class="nexus-stat-label">No team</span>
                    <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'unstaffed', 0)) }}</strong>
                    <span class="nexus-stat-helper">Targets without a team</span>
                </div>
            @endif
        </section>

        <div class="milcom-plan-workbench grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(25rem,0.9fr)_minmax(31rem,1.1fr)]" data-milcom-workbench>
            <section class="nexus-panel milcom-plan-targets min-w-0" aria-labelledby="operation-targets-title">
                <form
                    method="POST"
                    action="{{ $apiBase }}/operations/{{ $operationId }}/objectives/approve"
                    class="milcom-plan-targets__form group"
                    data-milcom-batch-form
                >
                    @csrf
                    <input type="hidden" name="generation_version" value="{{ $generationVersion }}">

                    <div class="nexus-panel__header">
                        <div>
                            <h2 id="operation-targets-title" class="nexus-section-title">Targets</h2>
                            <p class="mt-1 text-sm text-base-content/65">Up to 50 per page. Use J/K or the arrow keys to move.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="hidden cursor-pointer items-center gap-2 text-xs font-semibold md:flex">
                                <input type="checkbox" class="checkbox checkbox-sm checkbox-primary" data-milcom-select-all>
                                Select page
                            </label>
                            <span class="badge badge-outline">{{ number_format((int) data_get($summary, 'objective_count', $objectiveRows->count())) }} total</span>
                            @unless ($isActive)
                                <button
                                    type="submit"
                                    class="btn btn-primary btn-sm hidden md:inline-flex"
                                    formaction="{{ $apiBase }}/operations/{{ $operationId }}/objectives/approve-eligible"
                                    data-milcom-batch-command="approve-eligible"
                                    data-confirm="Approve every target that passes the final checks? Targets with blockers or warnings will be skipped."
                                    data-confirm-title="Approve eligible targets?"
                                    data-confirm-label="Approve"
                                >
                                    <x-icon name="o-check-circle" class="size-4" aria-hidden="true" />
                                    Approve all eligible
                                </button>
                                <details class="dropdown dropdown-end hidden md:block" data-milcom-approve-warnings>
                                    <summary class="btn btn-outline btn-sm">
                                        <x-icon name="o-exclamation-triangle" class="size-4" aria-hidden="true" />
                                        Approve all with warnings
                                    </summary>
                                    <div class="dropdown-content mt-2 grid w-80 gap-3 rounded-md border border-base-300 bg-base-100 p-4 shadow-lg">
                                        <div>
                                            <h3 class="font-semibold">Approve every reviewable target</h3>
                                            <p class="mt-1 text-xs leading-5 text-base-content/65">This approves clean teams and overrides warnings such as inactivity. Hard game blockers—vacation mode, war range, applicants, duplicate wars, or full offensive slots—are always skipped.</p>
                                        </div>
                                        <label class="grid gap-1.5">
                                            <span class="text-xs font-semibold">Officer reason</span>
                                            <textarea name="override_reason" minlength="10" maxlength="1000" rows="3" class="textarea textarea-sm w-full" placeholder="Why these warnings are acceptable"></textarea>
                                        </label>
                                        <button
                                            type="submit"
                                            class="btn btn-warning btn-sm"
                                            formaction="{{ $apiBase }}/operations/{{ $operationId }}/objectives/approve-reviewable"
                                            data-milcom-batch-command="approve-reviewable"
                                        >
                                            Approve all reviewable
                                        </button>
                                    </div>
                                </details>
                            @endunless
                        </div>
                    </div>

                    <div class="grid gap-3 border-b border-base-300 p-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                        <label class="input input-sm w-full">
                            <x-icon name="o-magnifying-glass" class="size-4 opacity-55" aria-hidden="true" />
                            <input
                                type="search"
                                name="search"
                                value="{{ data_get($filters, 'search') }}"
                                placeholder="Nation, leader, or alliance"
                                aria-label="Search targets"
                                data-milcom-search
                            >
                            <kbd class="kbd kbd-sm hidden border-base-300 bg-base-200 sm:inline-flex">/</kbd>
                        </label>
                        <div class="flex gap-2">
                            <select name="filter" class="select select-sm min-w-40 flex-1" aria-label="Target filter" data-milcom-filter>
                                <option value="needs_attention" @selected($activeFilter === 'needs_attention')>Needs attention</option>
                                <option value="all" @selected($activeFilter === 'all')>All targets</option>
                                <option value="critical" @selected($activeFilter === 'critical')>Critical</option>
                                <option value="blocked" @selected($activeFilter === 'blocked')>Blocked</option>
                                <option value="approved" @selected($activeFilter === 'approved')>Approved</option>
                                <option value="dispatched" @selected($activeFilter === 'dispatched')>Sent to Discord</option>
                                <option value="engaged" @selected($activeFilter === 'engaged')>Wars started</option>
                                <option value="finished" @selected($activeFilter === 'finished')>Finished</option>
                            </select>
                            <button type="submit" formmethod="GET" formaction="{{ url('/admin/milcom/plans/'.$operationId) }}" class="btn btn-outline btn-sm">Filter</button>
                        </div>
                    </div>

                    <div
                        class="hidden max-md:hidden items-center justify-between gap-3 border-b border-primary/25 bg-primary/5 px-3 py-2 md:group-has-[:checked]:flex md:px-4"
                        data-milcom-batch-actions
                    >
                        <p class="text-sm font-semibold"><span data-milcom-selected-count>0</span> selected on this page</p>
                        <div class="flex flex-wrap justify-end gap-2">
                            @if ($isActive)
                                <button type="submit" class="btn btn-primary btn-sm" formaction="{{ $apiBase }}/operations/{{ $operationId }}/dispatch" data-milcom-batch-command="dispatch">Create rooms for selected</button>
                            @else
                                <button type="submit" class="btn btn-outline btn-sm" formaction="{{ $apiBase }}/operations/{{ $operationId }}/objectives/approve" data-milcom-batch-command="approve">Approve selected</button>
                            @endif
                        </div>
                    </div>

                    <div
                        class="milcom-plan-target-list divide-y divide-base-300"
                        role="listbox"
                        aria-label="Plan targets"
                        aria-multiselectable="true"
                        data-milcom-objective-list
                        aria-busy="false"
                    >
                        @forelse ($objectiveRows as $objective)
                            @php
                                $objectiveId = (int) data_get($objective, 'id', 0);
                                $isSelected = (int) data_get($selectedObjective, 'id', 0) === $objectiveId;
                                $priority = (string) data_get($objective, 'priority_tier.value', data_get($objective, 'priority_tier', 'standard'));
                                $status = (string) data_get($objective, 'status.value', data_get($objective, 'status', 'review'));
                                $staffed = (int) data_get($objective, 'staffed_depth', data_get($objective, 'assignments_count', 0));
                                $minimum = (int) data_get($objective, 'minimum_team_depth', $priority === 'critical' ? 2 : 1);
                                $desired = (int) data_get($objective, 'desired_team_depth', $priority === 'critical' ? 3 : 1);
                                $hasBlockers = (bool) data_get($objective, 'has_blockers', $status === 'blocked');
                                $warningCount = (int) data_get($objective, 'warning_count', 0);
                                $warningSummary = data_get($objective, 'warning_summary');
                                $assignedNations = collect(data_get($objective, 'assigned_nations', []));
                                $attackCount = (int) data_get($objective, 'attack_count', 0);
                                $successfulAttackCount = (int) data_get($objective, 'successful_attack_count', 0);
                                $declarationOverdue = (bool) data_get($objective, 'declaration_overdue', false);
                                $firstHitOverdue = (bool) data_get($objective, 'first_hit_overdue', false);
                            @endphp
                            <article
                                class="relative grid grid-cols-1 items-stretch transition-colors md:grid-cols-[auto_minmax(0,1fr)] {{ $isSelected ? 'bg-primary/7' : 'hover:bg-base-200/60' }}"
                                data-milcom-objective-row
                                data-objective-id="{{ $objectiveId }}"
                            >
                                <label class="relative z-20 hidden cursor-pointer place-items-center px-3 md:grid" aria-label="Select {{ data_get($objective, 'target.nation_name', data_get($objective, 'target_name', 'target')) }}">
                                    <input type="checkbox" name="objective_ids[]" value="{{ $objectiveId }}" class="checkbox checkbox-sm checkbox-primary" data-milcom-objective-checkbox>
                                </label>
                                <button
                                    type="button"
                                    class="absolute inset-0 z-0 text-left focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary"
                                    role="option"
                                    aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                                    aria-label="View {{ data_get($objective, 'target.nation_name', data_get($objective, 'target_name', 'target')) }}"
                                    tabindex="{{ $isSelected || ($loop->first && ! $selectedObjective) ? '0' : '-1' }}"
                                    data-milcom-select-objective
                                    data-objective-id="{{ $objectiveId }}"
                                ></button>
                                <div class="pointer-events-none relative z-10 min-w-0 px-2 py-3 sm:px-3 md:col-start-2">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="truncate font-semibold">
                                                    <x-pw-nation-link
                                                        :nation-id="data_get($objective, 'target.id', data_get($objective, 'target_nation_id'))"
                                                        :label="data_get($objective, 'target.nation_name', data_get($objective, 'target_name', 'Unknown target'))"
                                                        class="pointer-events-auto relative z-20"
                                                    />
                                                </h3>
                                                <span class="badge {{ $priority === 'critical' ? 'badge-error badge-soft' : ($priority === 'high' ? 'badge-warning badge-soft' : 'badge-ghost') }}">{{ str($priority)->headline() }}</span>
                                                @if ($hasBlockers)
                                                    <span class="badge badge-error badge-outline">Blocked</span>
                                                @endif
                                                @if ($warningCount > 0)
                                                    <span class="badge badge-warning badge-soft">{{ $warningCount }} {{ \Illuminate\Support\Str::plural('warning', $warningCount) }}</span>
                                                @endif
                                            </div>
                                            <p class="mt-1 truncate text-xs nexus-text-muted">
                                                <x-pw-nation-link
                                                    :nation-id="data_get($objective, 'target.id', data_get($objective, 'target_nation_id'))"
                                                    :label="data_get($objective, 'target.leader_name', data_get($objective, 'leader_name', 'Unknown leader'))"
                                                    class="pointer-events-auto relative z-20"
                                                />
                                                <span aria-hidden="true">·</span>
                                                {{ data_get($objective, 'target.alliance.name', data_get($objective, 'alliance_name', 'No alliance')) }}
                                            </p>
                                            @if ($warningSummary)
                                                <p class="mt-2 flex items-start gap-1.5 text-xs leading-5 text-warning">
                                                    <x-icon name="o-exclamation-triangle" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                                    <span>{{ $warningSummary }}</span>
                                                </p>
                                            @endif
                                            @if ($declarationOverdue)
                                                <p class="mt-2 flex items-start gap-1.5 text-xs font-semibold leading-5 text-error">
                                                    <x-icon name="o-clock" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                                    <span>No declaration was matched before the deadline.</span>
                                                </p>
                                            @endif
                                            @if ($firstHitOverdue)
                                                <p class="mt-2 flex items-start gap-1.5 text-xs font-semibold leading-5 text-warning">
                                                    <x-icon name="o-exclamation-triangle" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                                    <span>The war started, but this team has no successful attack after {{ number_format((int) config('milcom.live.first_hit_grace_minutes', 15)) }} minutes.</span>
                                                </p>
                                            @endif
                                            <p class="mt-2 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-base-content/65">
                                                <span class="font-semibold">Assigned:</span>
                                                @forelse ($assignedNations as $assignedNation)
                                                    <span class="inline-flex items-center gap-1">
                                                        <x-pw-nation-link
                                                            :nation-id="data_get($assignedNation, 'id')"
                                                            :label="data_get($assignedNation, 'nation_name', 'Unknown nation')"
                                                            class="pointer-events-auto relative z-20"
                                                        />
                                                        @if ($isActive)
                                                            <span class="badge badge-ghost badge-xs">{{ str((string) data_get($assignedNation, 'status', 'proposed'))->headline() }}</span>
                                                        @endif
                                                    </span>
                                                @empty
                                                    <span class="nexus-text-muted">No team yet</span>
                                                @endforelse
                                            </p>
                                        </div>
                                        <span class="nexus-status {{ $priority === 'hold' ? 'nexus-status--neutral' : $statusTone($status) }} shrink-0">{{ $priority === 'hold' ? 'On hold' : $statusLabel($status) }}</span>
                                    </div>
                                    <div class="mt-3 flex items-center justify-between gap-4 text-xs text-base-content/65">
                                        <span class="tabular-nums">{{ number_format((float) data_get($objective, 'target.score', data_get($objective, 'score', 0)), 0) }} score · {{ number_format((int) data_get($objective, 'target.num_cities', data_get($objective, 'cities', 0))) }} cities</span>
                                        <span class="flex items-center gap-2 font-semibold tabular-nums {{ $staffed < $minimum ? 'text-error' : ($staffed < $desired ? 'text-warning' : 'text-success') }}">
                                            @if ($isActive)<span>{{ number_format($successfulAttackCount) }}/{{ number_format($attackCount) }} successful attacks</span>@endif
                                            <span>{{ $staffed }}/{{ $desired }} assigned</span>
                                        </span>
                                    </div>
                                    <progress
                                        class="progress {{ $staffed < $minimum ? 'progress-error' : ($staffed < $desired ? 'progress-warning' : 'progress-success') }} mt-2 h-1 w-full"
                                        value="{{ min($desired, $staffed) }}"
                                        max="{{ max(1, $desired) }}"
                                        aria-label="{{ $staffed }} of {{ $desired }} attackers assigned"
                                    ></progress>
                                </div>
                            </article>
                        @empty
                            <div class="nexus-empty-state" data-milcom-objective-empty>
                                <x-icon name="o-funnel" class="size-9 nexus-text-muted" aria-hidden="true" />
                                <div>
                                    <h3 class="text-lg font-semibold">No targets need attention</h3>
                                    <p class="mt-1 text-sm text-base-content/65">Try another filter, or regenerate teams after changing the plan setup.</p>
                                </div>
                            </div>
                        @endforelse
                    </div>

                    @if (is_object($objectives) && method_exists($objectives, 'hasPages') && $objectives->hasPages())
                        <div class="nexus-panel__footer">{{ $objectives->links() }}</div>
                    @else
                        <div class="nexus-panel__footer flex items-center justify-between gap-3 text-xs nexus-text-muted">
                            <span>Showing {{ number_format($objectiveRows->count()) }} of {{ number_format((int) data_get($summary, 'objective_count', $objectiveRows->count())) }}</span>
                            <span>Up to 50 per page</span>
                        </div>
                    @endif
                </form>
            </section>

            <aside class="nexus-panel milcom-plan-inspector" aria-labelledby="selected-target-title" data-milcom-inspector>
                <button type="button" class="milcom-plan-inspector__close btn btn-ghost btn-sm" data-milcom-close-inspector>
                    <x-icon name="o-arrow-left" class="size-4" aria-hidden="true" />
                    Back to targets
                </button>

                <div class="hidden" data-milcom-inspector-loading aria-hidden="true">
                    <div class="animate-pulse space-y-5 p-5 motion-reduce:animate-none">
                        <div class="h-7 w-2/3 rounded bg-base-300"></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="h-16 rounded bg-base-300"></div>
                            <div class="h-16 rounded bg-base-300"></div>
                            <div class="h-16 rounded bg-base-300"></div>
                            <div class="h-16 rounded bg-base-300"></div>
                        </div>
                        <div class="h-36 rounded bg-base-300"></div>
                    </div>
                </div>

                <div class="{{ $selectedObjective ? 'hidden' : '' }} nexus-empty-state min-h-96" data-milcom-inspector-empty>
                    <x-icon name="o-cursor-arrow-rays" class="size-9 nexus-text-muted" aria-hidden="true" />
                    <div>
                        <h2 class="text-lg font-semibold">Select a target</h2>
                        <p class="mt-1 text-sm text-base-content/65">Select a target to see its military, proposed team, blockers, match reasons, and alternatives.</p>
                    </div>
                </div>

                <div class="{{ $selectedObjective ? '' : 'hidden' }}" data-milcom-inspector-content>
                    @php
                        $selectedPriority = (string) data_get($selectedObjective, 'priority_tier.value', data_get($selectedObjective, 'priority_tier', 'standard'));
                        $selectedStatus = (string) data_get($selectedObjective, 'status.value', data_get($selectedObjective, 'status', 'review'));
                        $selectedDispatch = data_get($selectedObjective, 'dispatch');
                        $selectedDispatchStatus = (string) data_get($selectedDispatch, 'status', '');
                        $canApproveSelected = in_array($selectedStatus, ['pending', 'review', 'blocked'], true);
                        $targetData = data_get($selectedObjective, 'target', $selectedObjective);
                    @endphp
                    <div class="nexus-panel__header">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 id="selected-target-title" class="nexus-section-title truncate">
                                    <x-pw-nation-link
                                        :nation-id="data_get($targetData, 'id', data_get($selectedObjective, 'target_nation_id'))"
                                        :label="data_get($targetData, 'nation_name', data_get($selectedObjective, 'target_name', 'Selected target'))"
                                        data-milcom-field="target_name"
                                    />
                                </h2>
                                <span class="badge {{ $selectedPriority === 'critical' ? 'badge-error badge-soft' : ($selectedPriority === 'high' ? 'badge-warning badge-soft' : 'badge-ghost') }}" data-milcom-field="priority">{{ str($selectedPriority)->headline() }}</span>
                            </div>
                            <p class="mt-1 text-sm nexus-text-muted">
                                <x-pw-nation-link
                                    :nation-id="data_get($targetData, 'id', data_get($selectedObjective, 'target_nation_id'))"
                                    :label="data_get($targetData, 'leader_name', 'Unknown leader')"
                                    data-milcom-field="leader_name"
                                />
                                <span aria-hidden="true">·</span>
                                <span data-milcom-field="alliance_name">{{ data_get($targetData, 'alliance.name', data_get($selectedObjective, 'alliance_name', 'No alliance')) }}</span>
                            </p>
                        </div>
                        <span class="nexus-status {{ $selectedPriority === 'hold' ? 'nexus-status--neutral' : $statusTone($selectedStatus) }}" data-milcom-field="status">{{ $selectedPriority === 'hold' ? 'On hold' : $statusLabel($selectedStatus) }}</span>
                    </div>

                    <nav class="milcom-plan-inspector__nav" aria-label="Selected target sections" data-milcom-inspector-nav>
                        <button type="button" class="btn btn-ghost btn-sm" data-milcom-inspector-jump="target-readiness-title" aria-current="true">Overview</button>
                        <button type="button" class="btn btn-ghost btn-sm" data-milcom-inspector-jump="proposed-team-title">Team</button>
                        <button type="button" class="btn btn-ghost btn-sm" data-milcom-inspector-jump="approval-checks-title">Review</button>
                        @unless ($isActive)
                            <button type="button" class="btn btn-ghost btn-sm hidden md:inline-flex" data-milcom-inspector-jump="staffing-controls-title">Edit target</button>
                        @endunless
                    </nav>

                    <div class="milcom-plan-inspector__body divide-y divide-base-300" data-milcom-inspector-scroll>
                        <section class="p-4 md:p-5" aria-labelledby="target-readiness-title">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 id="target-readiness-title" class="font-semibold">Target details</h3>
                                    <p class="mt-1 text-xs nexus-text-muted">Data from <span data-milcom-field="freshness">{{ $relativeTime(data_get($selectedRecommendation, 'snapshot_at', data_get($selectedObjective, 'snapshot_at'))) }}</span></p>
                                </div>
                                <a href="{{ data_get($selectedObjective, 'target_url', 'https://politicsandwar.com/nation/id='.data_get($targetData, 'id', '')) }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm">
                                    P&amp;W
                                    <x-icon name="o-arrow-top-right-on-square" class="size-4" aria-hidden="true" />
                                </a>
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-base-300 bg-base-300">
                                <div class="bg-base-100 p-3">
                                    <dt class="nexus-stat-label">Score</dt>
                                    <dd class="mt-1 font-semibold tabular-nums" data-milcom-field="score">{{ number_format((float) data_get($targetData, 'score', 0), 0) }}</dd>
                                </div>
                                <div class="bg-base-100 p-3">
                                    <dt class="nexus-stat-label">Cities</dt>
                                    <dd class="mt-1 font-semibold tabular-nums" data-milcom-field="cities">{{ number_format((int) data_get($targetData, 'num_cities', data_get($targetData, 'cities', 0))) }}</dd>
                                </div>
                            </dl>
                            <x-milcom.military-summary :nation="$targetData" variant="tiles" dynamic class="mt-3" label="Target military" />
                        </section>

                        <section class="p-4 md:p-5" aria-labelledby="proposed-team-title">
                            <div class="flex items-end justify-between gap-3">
                                <div>
                                    <h3 id="proposed-team-title" class="font-semibold">Proposed team</h3>
                                    <p class="mt-1 text-xs nexus-text-muted">Match score and free offensive slots.</p>
                                </div>
                                <span class="text-sm font-semibold tabular-nums"><span data-milcom-field="staffed_depth">{{ $selectedTeam->count() }}</span>/<span data-milcom-field="desired_depth">{{ (int) data_get($selectedObjective, 'desired_team_depth', 3) }}</span></span>
                            </div>
                            <div class="mt-4 divide-y divide-base-300 border-y border-base-300" data-milcom-list="team">
                                @forelse ($selectedTeam as $member)
                                    @php
                                        $memberData = data_get($member, 'friendly', data_get($member, 'nation', $member));
                                    @endphp
                                    <article class="flex items-center justify-between gap-3 py-3">
                                        <div class="min-w-0">
                                            <h4 class="truncate font-semibold">
                                                <x-pw-nation-link
                                                    :nation-id="data_get($memberData, 'id', data_get($member, 'friendly_nation_id'))"
                                                    :label="data_get($memberData, 'nation_name', data_get($member, 'nation_name', 'Unknown nation'))"
                                                    data-milcom-nation-id="{{ data_get($memberData, 'id', data_get($member, 'friendly_nation_id')) }}"
                                                    data-milcom-nation-name="{{ data_get($memberData, 'nation_name', data_get($member, 'nation_name', 'Unknown nation')) }}"
                                                />
                                            </h4>
                                            <p class="mt-1 text-xs nexus-text-muted">{{ number_format((int) data_get($memberData, 'num_cities', data_get($memberData, 'cities', 0))) }} cities · {{ data_get($member, 'offensive_slots_available', data_get($memberData, 'offensive_slots_available', '?')) }} slots free</p>
                                            <x-milcom.military-summary :nation="$memberData" class="mt-2" label="Assigned nation military" />
                                        </div>
                                        <div class="text-right">
                                            <p class="font-semibold tabular-nums">{{ number_format((float) data_get($member, 'score', data_get($member, 'pair_score', 0)), 0) }}</p>
                                            <p class="text-xs nexus-text-muted">{{ number_format((float) data_get($member, 'confidence', 0), 0) }}% confidence</p>
                                        </div>
                                    </article>
                                @empty
                                    <p class="py-5 text-center text-sm nexus-text-muted">No eligible team found.</p>
                                @endforelse
                            </div>
                        </section>

                        <section class="grid gap-5 p-4 md:grid-cols-2 md:p-5">
                            <div>
                                <h3 id="approval-checks-title" class="font-semibold">Approval checks</h3>
                                <h4 class="mt-3 text-xs font-semibold text-base-content/65">Blockers</h4>
                                <ul class="mt-2 grid gap-2" data-milcom-list="blockers">
                                    @forelse ($selectedBlockers as $blocker)
                                        @php
                                            $blockerText = is_string($blocker) ? $blocker : data_get($blocker, 'message', data_get($blocker, 'label', 'Blocked'));
                                        @endphp
                                        <li class="flex items-start gap-2 text-sm text-error">
                                            <x-icon name="o-x-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                            <span>{{ $blockerText }}</span>
                                        </li>
                                    @empty
                                        <li class="flex items-start gap-2 text-sm text-success">
                                            <x-icon name="o-check-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                            <span>No blockers.</span>
                                        </li>
                                    @endforelse
                                </ul>

                                <h4 class="mt-4 text-xs font-semibold text-base-content/65">Warnings</h4>
                                <ul class="mt-2 grid gap-2" data-milcom-list="warnings">
                                    @forelse ($selectedWarnings as $warning)
                                        @php
                                            $warningText = is_string($warning) ? $warning : data_get($warning, 'message', data_get($warning, 'label', 'Warning'));
                                            $warningNationId = (int) data_get($warning, 'context.nation_id', 0);
                                            $warningNationName = data_get($warning, 'context.nation_name');
                                            $warningSuffix = $warningNationId > 0 && str_starts_with($warningText, 'This nation')
                                                ? substr($warningText, strlen('This nation'))
                                                : ': '.$warningText;
                                        @endphp
                                        <li class="flex items-start gap-2 text-sm text-warning">
                                            <x-icon name="o-exclamation-triangle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                            <span>
                                                @if ($warningNationId > 0)
                                                    <x-pw-nation-link :nation-id="$warningNationId" :label="$warningNationName ?: 'Nation '.$warningNationId" />{{ $warningSuffix }}
                                                @else
                                                    {{ $warningText }}
                                                @endif
                                            </span>
                                        </li>
                                    @empty
                                        <li class="text-sm nexus-text-muted">No warnings.</li>
                                    @endforelse
                                </ul>

                                @unless ($isActive)
                                    <label
                                        class="{{ $selectedWarnings->isNotEmpty() && $selectedBlockers->isEmpty() ? 'block' : 'hidden' }} mt-4 rounded-md border border-warning/30 bg-warning/5 p-3"
                                        data-milcom-plan-warning-override
                                    >
                                        <span class="block text-sm font-semibold">Why approve despite these warnings?</span>
                                        <span class="mt-1 block text-xs leading-5 text-base-content/65">Required to approve this target. {{ config('app.name') }} saves the reason with the team.</span>
                                        <textarea
                                            name="override_reason"
                                            form="milcom-approve-target-form"
                                            minlength="10"
                                            maxlength="1000"
                                            class="textarea textarea-sm mt-3 min-h-20 w-full"
                                            placeholder="Explain why this team is still the right choice"
                                            @required($selectedWarnings->isNotEmpty() && $selectedBlockers->isEmpty())
                                        ></textarea>
                                    </label>
                                @endunless
                            </div>
                            <div>
                                <h3 class="font-semibold">Why these nations</h3>
                                <ul class="mt-3 grid gap-2 text-sm text-base-content/70" data-milcom-list="reasons">
                                    @forelse ($selectedReasons as $reason)
                                        <li class="flex items-start gap-2">
                                            <span class="mt-2 size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                                            <span>{{ is_string($reason) ? $reason : data_get($reason, 'message', data_get($reason, 'label', 'Match quality')) }}</span>
                                        </li>
                                    @empty
                                        <li>Regenerate teams to see match reasons.</li>
                                    @endforelse
                                </ul>
                            </div>
                        </section>

                        <section class="p-4 md:p-5" aria-labelledby="alternatives-title">
                            <div>
                                <h3 id="alternatives-title" class="font-semibold">Alternatives</h3>
                                <p class="mt-1 text-xs nexus-text-muted">Each team changes at least one nation.</p>
                            </div>
                            <div class="mt-4 grid gap-3" data-milcom-list="alternatives">
                                @forelse ($selectedAlternatives as $alternative)
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
                                        @unless ($isActive)
                                            <button type="button" class="btn btn-outline btn-sm hidden md:inline-flex" data-milcom-use-alternative data-alternative-index="{{ $loop->index }}">Use team</button>
                                        @endunless
                                    </article>
                                @empty
                                    <p class="rounded-md border border-dashed border-base-300 p-4 text-sm nexus-text-muted">No other eligible team found.</p>
                                @endforelse
                            </div>
                        </section>

                        @unless ($isActive)
                        <section class="hidden p-4 md:block md:p-5" aria-labelledby="staffing-controls-title">
                            <div>
                                <h3 id="staffing-controls-title" class="font-semibold">Edit target</h3>
                                <p class="mt-1 text-xs nexus-text-muted">{{ config('app.name') }} checks the plan version and available slots when you approve.</p>
                            </div>

                            <form
                                method="PATCH"
                                action="{{ $apiBase }}/objectives/{{ (int) data_get($selectedObjective, 'id', 0) }}"
                                class="mt-4 grid gap-3 rounded-md border border-base-300 p-3 sm:grid-cols-2"
                                data-milcom-command="update-objective"
                            >
                                @csrf
                                <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                                <label class="block"><span class="label px-0 text-xs">Priority</span><select name="priority_tier" class="select select-sm w-full">@foreach (['critical', 'high', 'standard', 'hold'] as $tier)<option value="{{ $tier }}" @selected($selectedPriority === $tier)>{{ str($tier)->headline() }}</option>@endforeach</select></label>
                                <label class="block"><span class="label px-0 text-xs">War type</span><select name="war_type" class="select select-sm w-full">@foreach (['ORDINARY', 'ATTRITION', 'RAID'] as $warType)<option value="{{ $warType }}" @selected(data_get($selectedObjective, 'war_type', 'ORDINARY') === $warType)>{{ str($warType)->headline() }}</option>@endforeach</select></label>
                                <label class="block"><span class="label px-0 text-xs">Minimum attackers</span><input type="number" min="0" max="5" name="minimum_team_depth" value="{{ (int) data_get($selectedObjective, 'minimum_team_depth', 1) }}" class="input input-sm w-full"></label>
                                <label class="block"><span class="label px-0 text-xs">Desired attackers</span><input type="number" min="0" max="5" name="desired_team_depth" value="{{ (int) data_get($selectedObjective, 'desired_team_depth', 1) }}" class="input input-sm w-full"></label>
                                <label class="block sm:col-span-2"><span class="label px-0 text-xs">Declare by</span><input type="datetime-local" name="deadline_at" value="{{ data_get($selectedObjective, 'deadline_at')?->format('Y-m-d\TH:i') }}" class="input input-sm w-full"></label>
                                <label class="block sm:col-span-2"><span class="label px-0 text-xs">War reason</span><input type="text" maxlength="255" name="war_reason" value="{{ data_get($selectedObjective, 'war_reason', data_get($operation, 'default_war_reason')) }}" class="input input-sm w-full"></label>
                                <div class="flex justify-end sm:col-span-2"><button type="submit" class="btn btn-outline btn-sm">Save target</button></div>
                            </form>

                            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                <form method="POST" action="{{ $apiBase }}/objectives/{{ (int) data_get($selectedObjective, 'id', 0) }}/assignments/manual" class="rounded-md border border-base-300 p-3" data-milcom-command="manual-assignment">
                                    @csrf
                                    <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                                    <h4 class="text-sm font-semibold">Assign a nation</h4>
                                    <div class="mt-3 grid gap-3">
                                        <input type="number" min="1" name="friendly_nation_id" class="input input-sm w-full" placeholder="Friendly nation ID" required>
                                        <textarea name="override_reason" minlength="10" maxlength="1000" class="textarea textarea-sm w-full" placeholder="Why you want to assign this nation" required></textarea>
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="lock" value="1" class="checkbox checkbox-sm" checked> Keep this nation on the team when teams are rebuilt</label>
                                        <button type="submit" class="btn btn-outline btn-sm">Assign nation</button>
                                    </div>
                                </form>

                                <form method="DELETE" action="#" class="rounded-md border border-base-300 p-3" data-milcom-command="release-assignment" data-milcom-release-form data-release-template="{{ $apiBase }}/objectives/{objective}/assignments/{assignment}">
                                    @csrf
                                    <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                                    <h4 class="text-sm font-semibold">Remove assigned nation</h4>
                                    <div class="mt-3 grid gap-3">
                                        <select name="assignment_id" class="select select-sm w-full" data-milcom-assignment-select required>
                                            @foreach ($selectedTeam as $member)
                                                <option value="{{ (int) data_get($member, 'id', 0) }}">{{ data_get($member, 'friendly.nation_name', 'Assigned nation #'.data_get($member, 'id')) }}</option>
                                            @endforeach
                                        </select>
                                        <textarea name="reason" minlength="10" maxlength="1000" class="textarea textarea-sm w-full" placeholder="Why you want to remove this nation" required></textarea>
                                        <button type="submit" class="btn btn-outline btn-sm" @disabled($selectedTeam->isEmpty())>Remove nation</button>
                                    </div>
                                </form>
                            </div>

                            <form method="POST" action="{{ $apiBase }}/objectives/{{ (int) data_get($selectedObjective, 'id', 0) }}/cancel" class="mt-3 flex flex-col gap-3 rounded-md border border-error/30 bg-error/5 p-3 sm:flex-row sm:items-end" data-milcom-command="cancel-objective" data-confirm="Cancel this target and release its unused slots?">
                                @csrf
                                <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                                <label class="block min-w-0 flex-1"><span class="label px-0 text-xs">Reason</span><input type="text" minlength="10" maxlength="1000" name="reason" class="input input-sm w-full" required></label>
                                <button type="submit" class="btn btn-error btn-outline btn-sm">Cancel target</button>
                            </form>
                        </section>
                        @endunless
                    </div>

                    <div class="nexus-panel__footer milcom-plan-inspector__actions hidden items-center justify-between gap-3 md:flex">
                        <p class="text-xs nexus-text-muted">{{ $isActive ? 'Delivery actions are safe to retry.' : 'You cannot override hard game blockers.' }}</p>
                        <div class="flex gap-2">
                            <form
                                method="POST"
                                action="{{ $apiBase }}/objectives/{{ (int) data_get($selectedObjective, 'id', 0) }}/dispatch/retry"
                                class="{{ $selectedDispatchStatus === 'failed' ? 'inline-flex' : 'hidden' }}"
                                data-milcom-command="retry-dispatch"
                                data-milcom-retry-dispatch
                            >
                                @csrf
                                <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                                <button type="submit" class="btn btn-error btn-outline btn-sm">Retry Discord room</button>
                            </form>
                            @unless ($isActive)
                                <button type="button" class="btn btn-outline btn-sm" data-milcom-inspector-jump="alternatives-title">Change team</button>
                                <form
                                    id="milcom-approve-target-form"
                                    method="POST"
                                    action="{{ $apiBase }}/objectives/{{ (int) data_get($selectedObjective, 'id', 0) }}/approve"
                                    class="{{ $canApproveSelected ? 'inline-flex' : 'hidden' }}"
                                    data-milcom-command="approve-objective"
                                >
                                    @csrf
                                    <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                                    <button type="submit" class="btn btn-primary btn-sm" @disabled($selectedBlockers->isNotEmpty() || ! $canApproveSelected)>Approve target</button>
                                </form>
                            @endunless
                            <form
                                method="POST"
                                action="{{ $apiBase }}/objectives/{{ (int) data_get($selectedObjective, 'id', 0) }}/dispatch"
                                class="{{ $isActive && $selectedStatus === 'approved' ? 'inline-flex' : 'hidden' }}"
                                data-milcom-command="dispatch-objective"
                                data-milcom-dispatch-objective
                            >
                                @csrf
                                <input type="hidden" name="generation_version" value="{{ $generationVersion }}">
                                <button type="submit" class="btn btn-primary btn-sm">Create Discord room</button>
                            </form>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
        @endif

        <p class="sr-only" role="status" aria-live="polite" data-milcom-status></p>
    </div>

    @include('admin.milcom.partials.scripts')
@endsection

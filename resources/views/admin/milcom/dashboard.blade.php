@extends('layouts.admin')

@section('title', 'Milcom dashboard')

@php
    $dashboard = $dashboard ?? [];
    $summary = $summary ?? data_get($dashboard, 'summary', []);
    $exceptionRows = collect($exceptions ?? data_get($dashboard, 'exceptions', []))->take(50);
    $operationRows = collect($operations ?? data_get($dashboard, 'operations', []))->take(12);
    $apiBase = $apiBase ?? url('/api/v1/milcom');
    $relativeTime = static function ($value): string {
        if (is_object($value) && method_exists($value, 'diffForHumans')) {
            return $value->diffForHumans();
        }

        return filled($value) ? (string) $value : 'Not yet';
    };
    $statusTone = static fn (string $status): string => match ($status) {
        'active', 'sent', 'succeeded', 'completed' => 'nexus-status--success',
        'failed', 'blocked', 'conflict' => 'nexus-status--error',
        'queued', 'running', 'dispatching', 'generating' => 'nexus-status--info',
        'review', 'stale', 'warning' => 'nexus-status--warning',
        default => 'nexus-status--neutral',
    };
    $statusLabel = static fn (string $status): string => match ($status) {
        'countering' => 'Counter in progress',
        'dispatching' => 'Creating Discord rooms',
        'dispatched' => 'Sent to Discord',
        'engaged' => 'War started',
        'generating' => 'Building teams',
        'review' => 'Ready for review',
        default => str($status)->headline()->toString(),
    };
@endphp

@section('content')
    <div
        data-milcom-app="dashboard"
        data-api-base="{{ $apiBase }}"
        data-summary-endpoint="{{ $apiBase }}/dashboard"
        class="contents"
    >
        <x-header title="Milcom dashboard" separator use-h1>
            <x-slot:subtitle>See urgent counters, coverage gaps, old team data, and Discord errors first.</x-slot:subtitle>
            <x-slot:actions>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ url('/admin/milcom/plans') }}" class="btn btn-outline">
                        <x-icon name="o-map" class="size-5" aria-hidden="true" />
                        Mass plans
                    </a>
                    <a href="{{ url('/admin/milcom/counters') }}" class="btn btn-primary">
                        <x-icon name="o-bolt" class="size-5" aria-hidden="true" />
                        Counter queue
                    </a>
                </div>
            </x-slot:actions>
        </x-header>

        @include('admin.milcom.partials.navigation', ['milcomCurrent' => 'dashboard'])

        <div class="hidden alert alert-error items-start" role="alert" data-milcom-feedback>
            <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <div class="font-semibold" data-milcom-feedback-title>Could not refresh Milcom</div>
                <p class="text-sm" data-milcom-feedback-message>Showing the last loaded data.</p>
            </div>
        </div>

        <section class="nexus-metrics" aria-label="Milcom summary">
            <a href="{{ url('/admin/milcom/counters?filter=urgent') }}" class="nexus-metric bg-error/5 transition-colors hover:bg-error/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <span class="nexus-stat-label">Urgent counters</span>
                <strong class="nexus-stat-value text-error" data-milcom-value="urgent_counters">{{ number_format((int) data_get($summary, 'urgent_counters', 0)) }}</strong>
                <span class="nexus-stat-helper">Waiting for review</span>
            </a>
            <a href="{{ url('/admin/milcom/plans?filter=critical_gaps') }}" class="nexus-metric transition-colors hover:bg-base-200/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <span class="nexus-stat-label">Critical gaps</span>
                <strong class="nexus-stat-value" data-milcom-value="critical_gaps">{{ number_format((int) data_get($summary, 'critical_gaps', 0)) }}</strong>
                <span class="nexus-stat-helper">Targets below minimum</span>
            </a>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Outdated teams</span>
                <strong class="nexus-stat-value" data-milcom-value="stale_runs">{{ number_format((int) data_get($summary, 'stale_runs', 0)) }}</strong>
                <span class="nexus-stat-helper">Build teams again</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Discord room errors</span>
                <strong class="nexus-stat-value" data-milcom-value="discord_failures">{{ number_format((int) data_get($summary, 'discord_failures', 0)) }}</strong>
                <span class="nexus-stat-helper">Rooms not created</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Active work</span>
                <strong class="nexus-stat-value" data-milcom-value="live_operations">{{ number_format((int) data_get($summary, 'live_operations', $operationRows->count())) }}</strong>
                <span class="nexus-stat-helper">Plans and counters underway</span>
            </div>
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(19rem,0.65fr)]">
            <section class="nexus-panel min-w-0" aria-labelledby="milcom-attention-title">
                <div class="nexus-panel__header">
                    <div>
                        <h2 id="milcom-attention-title" class="nexus-section-title">Needs attention</h2>
                        <p class="mt-1 text-sm text-base-content/65">Sorted by urgency, then age.</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" data-milcom-refresh>
                        <x-icon name="o-arrow-path" class="size-4" aria-hidden="true" />
                        Refresh
                    </button>
                </div>

                <div class="divide-y divide-base-300" data-milcom-exception-list aria-live="polite" aria-busy="false">
                    @foreach ($exceptionRows as $exception)
                        @php
                            $type = (string) data_get($exception, 'type', 'exception');
                            $severity = (string) data_get($exception, 'severity', 'warning');
                            $href = data_get($exception, 'url') ?: ($type === 'counter' ? url('/admin/milcom/counters') : url('/admin/milcom/plans'));
                        @endphp
                        <article class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between md:p-5" data-milcom-exception-row>
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="mt-0.5 inline-grid size-9 shrink-0 place-items-center rounded-md {{ $severity === 'error' ? 'bg-error/10 text-error' : ($severity === 'info' ? 'bg-info/10 text-info' : 'bg-warning/10 text-warning') }}">
                                    <x-icon :name="$type === 'counter' ? 'o-bolt' : ($type === 'discord' ? 'o-chat-bubble-left-right' : 'o-exclamation-triangle')" class="size-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-semibold">
                                            @if ($type === 'raid_policy')
                                                {{ data_get($exception, 'title', 'Raid policy violation') }}
                                            @elseif (data_get($exception, 'nation_id'))
                                                {{ data_get($exception, 'title_prefix') }}
                                                <x-pw-nation-link :nation-id="data_get($exception, 'nation_id')" :label="data_get($exception, 'nation_name', 'Unknown nation')" />
                                            @else
                                                {{ data_get($exception, 'title', 'Milcom issue') }}
                                            @endif
                                        </h3>
                                        <span class="badge badge-outline">{{ str($type)->headline() }}</span>
                                    </div>
                                    <p class="mt-1 text-sm leading-6 text-base-content/65">
                                        @if ($type === 'raid_policy')
                                            <x-pw-nation-link :nation-id="data_get($exception, 'attacker_nation_id')" :label="data_get($exception, 'attacker_nation_name', 'Unknown nation')" />
                                            declared war on
                                            <x-pw-nation-link :nation-id="data_get($exception, 'defender_nation_id')" :label="data_get($exception, 'defender_nation_name', 'Unknown nation')" />.
                                        @elseif (data_get($exception, 'description_nation_id'))
                                            <x-pw-nation-link :nation-id="data_get($exception, 'description_nation_id')" :label="data_get($exception, 'description_nation_name', 'Unknown nation')" />
                                            {{ data_get($exception, 'description_suffix') }}
                                        @else
                                            {{ data_get($exception, 'description', 'Check the latest Milcom data before continuing.') }}
                                        @endif
                                    </p>
                                    @if ($type === 'raid_policy')
                                        <ul class="mt-2 grid gap-1 text-sm text-error/85">
                                            @foreach (data_get($exception, 'reasons', []) as $reason)
                                                <li class="flex items-start gap-2">
                                                    <span aria-hidden="true">•</span>
                                                    <span>{{ data_get($reason, 'message') }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    <p class="mt-1 text-xs text-base-content/55">Found {{ $relativeTime(data_get($exception, 'detected_at')) }}</p>
                                </div>
                            </div>
                            @if ($type === 'raid_policy')
                                <div class="flex shrink-0 flex-wrap gap-2">
                                    <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm">
                                        War timeline
                                    </a>
                                    <form
                                        method="POST"
                                        action="{{ data_get($exception, 'dismiss_url') }}"
                                        data-milcom-command="dismiss-raid-alert"
                                        data-confirm="Dismiss this raid-policy alert for all Milcom officers? The event will remain in history."
                                        data-confirm-title="Dismiss raid alert?"
                                        data-confirm-label="Dismiss alert"
                                    >
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">Dismiss</button>
                                    </form>
                                </div>
                            @else
                                <a href="{{ $href }}" class="btn btn-outline btn-sm shrink-0">Review</a>
                            @endif
                        </article>
                    @endforeach
                    <div class="nexus-empty-state {{ $exceptionRows->isEmpty() ? '' : 'hidden' }}" data-milcom-exception-empty>
                        <x-icon name="o-shield-check" class="size-9 text-success" aria-hidden="true" />
                        <div>
                            <h3 class="text-lg font-semibold">Nothing needs attention</h3>
                            <p class="mt-1 text-sm text-base-content/65">New counters, raid-policy violations, staffing gaps, and Discord errors will appear here.</p>
                        </div>
                    </div>
                </div>
            </section>

            <aside class="nexus-panel min-w-0" aria-labelledby="live-operations-title">
                <div class="nexus-panel__header">
                    <div>
                        <h2 id="live-operations-title" class="nexus-section-title">Live plans and counters</h2>
                        <p class="mt-1 text-sm text-base-content/65">Active plans and counters.</p>
                    </div>
                </div>
                <div class="divide-y divide-base-300">
                    @forelse ($operationRows as $operation)
                        @php
                            $operationId = (int) data_get($operation, 'id', 0);
                            $operationType = (string) data_get($operation, 'type.value', data_get($operation, 'type', 'plan'));
                            $operationStatus = (string) data_get($operation, 'status.value', data_get($operation, 'status', 'active'));
                            $operationHref = $operationType === 'counter'
                                ? url('/admin/milcom/counters?operation='.$operationId)
                                : url('/admin/milcom/plans/'.$operationId);
                        @endphp
                        <a href="{{ $operationHref }}" class="block p-4 transition-colors hover:bg-base-200/60 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="truncate font-semibold">{{ data_get($operation, 'name', $operationType === 'counter' ? 'Counter response' : 'Untitled plan') }}</h3>
                                    <p class="mt-1 text-xs text-base-content/55">Updated {{ $relativeTime(data_get($operation, 'updated_at')) }}</p>
                                </div>
                                <span class="nexus-status {{ $statusTone($operationStatus) }}">{{ $statusLabel($operationStatus) }}</span>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3 text-sm text-base-content/65">
                                <span>{{ number_format((int) data_get($operation, 'objectives_attention', 0)) }} flagged</span>
                                <span class="tabular-nums">{{ number_format((float) data_get($operation, 'coverage_percent', 0), 0) }}%</span>
                            </div>
                            <progress class="progress progress-primary mt-2 h-1.5 w-full" value="{{ min(100, max(0, (float) data_get($operation, 'coverage_percent', 0))) }}" max="100" aria-label="Coverage {{ number_format((float) data_get($operation, 'coverage_percent', 0), 0) }} percent"></progress>
                        </a>
                    @empty
                        <div class="nexus-empty-state min-h-48">
                            <x-icon name="o-map" class="size-8 text-base-content/35" aria-hidden="true" />
                            <div>
                                <h3 class="font-semibold">No active plans or counters</h3>
                                <p class="mt-1 text-sm text-base-content/65">Create a plan or approve a counter to start.</p>
                            </div>
                        </div>
                    @endforelse
                </div>
            </aside>
        </div>

        <p class="sr-only" role="status" aria-live="polite" data-milcom-status></p>
    </div>

    @include('admin.milcom.partials.scripts')
@endsection

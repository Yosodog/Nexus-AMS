@extends('layouts.admin')

@section('title', 'Milcom history')

@php
    $eventLabels = [
        'assignment.alternative_selected' => 'Alternative team selected',
        'assignment.completed' => 'War completed',
        'assignment.engaged' => 'War declared',
        'assignment.manually_set' => 'Nation added by an officer',
        'assignment.released' => 'Nation removed from the team',
        'incident.countering' => 'Counter started',
        'incident.covered_by_plan' => 'Covered by an active plan',
        'objective.approved' => 'Target approved',
        'objective.cancelled' => 'Target cancelled',
        'objective.completed' => 'Target completed',
        'objective.discord_archive_queued' => 'Discord room is being archived',
        'objective.discord_failed' => 'Discord room failed',
        'objective.discord_queued' => 'Discord room started',
        'objective.discord_retry_queued' => 'Discord room retry started',
        'objective.discord_room_attached' => 'Discord room created',
        'objective.expired' => 'Target expired',
        'objective.updated' => 'Target updated',
        'operation.archived' => 'Record archived',
        'operation.activated' => 'Operation started',
        'operation.cloned' => 'New wave created',
        'operation.completed' => 'Operation ended',
        'operation.created' => 'Plan created',
        'operation.scope_committed' => 'Alliances and targets saved',
        'recommendation.failed' => 'Could not build teams',
        'recommendation.queued' => 'Team building queued',
        'recommendation.succeeded' => 'Teams built',
    ];
    $eventLabel = static function (string $eventType) use ($eventLabels): string {
        return match (true) {
            str_starts_with($eventType, 'war.attack.') => 'War attack recorded',
            str_starts_with($eventType, 'war.unplanned_declaration.') => 'Unplanned war declared',
            str_starts_with($eventType, 'capacity.conflict.') => 'Offensive slot conflict found',
            default => $eventLabels[$eventType] ?? str($eventType)->replace('.', ' ')->headline()->toString(),
        };
    };
@endphp

@section('content')
    <div data-milcom-app="archive-operation" data-api-base="{{ url('/api/v1/milcom') }}" class="contents">
    <x-header :title="$operation->name" separator use-h1>
        <x-slot:subtitle>Review the final targets, teams, and timeline.</x-slot:subtitle>
        <x-slot:actions>
            @if ($operation->type === \App\Domain\Milcom\Enums\OperationType::Plan)
                <a href="{{ route('admin.milcom.plans.show', ['operation' => $operation, 'stage' => 'stats']) }}" class="btn btn-outline">
                    <x-icon name="o-chart-bar" class="size-5" aria-hidden="true" />
                    View stats
                </a>
            @endif
            @if ($operation->status === \App\Domain\Milcom\Enums\OperationStatus::Completed)
                <form method="POST" action="{{ url('/api/v1/milcom/operations/'.$operation->id.'/archive') }}" data-milcom-command="archive-operation" data-success-redirect data-confirm="Archive this record? You can still view its history.">
                    @csrf
                    <input type="hidden" name="generation_version" value="{{ $operation->generation_version }}">
                    <button type="submit" class="btn btn-primary">Archive record</button>
                </form>
            @endif
            <a href="{{ route('admin.milcom.archive') }}" class="btn btn-outline">Back to archive</a>
        </x-slot:actions>
    </x-header>

    @include('admin.milcom.partials.navigation', ['milcomCurrent' => 'archive'])

    <section class="nexus-metrics" aria-label="Archived Milcom summary">
        <div class="nexus-metric"><span class="nexus-stat-label">Status</span><strong class="nexus-stat-value text-xl">{{ str($operation->status->value)->headline() }}</strong></div>
        <div class="nexus-metric"><span class="nexus-stat-label">Targets</span><strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'objective_count', 0)) }}</strong></div>
        <div class="nexus-metric"><span class="nexus-stat-label">Critical coverage</span><strong class="nexus-stat-value">{{ number_format((float) data_get($summary, 'critical_minimum_coverage_percent', 0), 0) }}%</strong></div>
        <div class="nexus-metric"><span class="nexus-stat-label">Desired staffing</span><strong class="nexus-stat-value">{{ number_format((float) data_get($summary, 'desired_depth_percent', 0), 0) }}%</strong></div>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <section class="nexus-panel">
            <div class="nexus-panel__header"><div><h2 class="nexus-section-title">Targets</h2><p class="mt-1 text-sm text-base-content/65">Final teams and results.</p></div></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Target</th><th>Priority</th><th>Assigned team</th><th>Status</th><th>Completed</th></tr></thead>
                    <tbody>
                        @forelse ($objectives as $objective)
                            <tr>
                                <td>
                                    <div class="font-semibold">
                                        <x-pw-nation-link :nation-id="$objective->target_nation_id" :label="$objective->target?->nation_name ?? 'Unknown target'" />
                                    </div>
                                    <div class="text-xs text-base-content/55">{{ $objective->target?->alliance?->name ?? 'No alliance' }}</div>
                                </td>
                                <td>{{ str($objective->priority_tier->value)->headline() }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-x-2 gap-y-1">
                                        @forelse ($objective->assignments as $assignment)
                                            <span class="inline-flex items-center gap-1.5">
                                                <x-pw-nation-link
                                                    :nation-id="$assignment->friendly_nation_id"
                                                    :label="$assignment->friendlyNation?->nation_name ?? 'Unknown nation'"
                                                />
                                                <span class="badge badge-ghost badge-xs">{{ str($assignment->status->value)->headline() }}</span>
                                            </span>
                                        @empty
                                            <span class="text-base-content/55">No assigned nations</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td><span class="badge badge-ghost">{{ str($objective->status->value)->headline() }}</span></td>
                                <td>{{ $objective->completed_at?->diffForHumans() ?? $objective->expired_at?->diffForHumans() ?? 'Not recorded' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="nexus-empty-state">No targets were saved.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($objectives->hasPages())<div class="nexus-panel__footer">{{ $objectives->links() }}</div>@endif
        </section>

        <aside class="nexus-panel xl:sticky xl:top-4">
            <div class="nexus-panel__header"><div><h2 class="nexus-section-title">Timeline</h2><p class="mt-1 text-sm text-base-content/65">Latest 50 updates.</p></div></div>
            <ol class="divide-y divide-base-300">
                @forelse ($events->take(50) as $event)
                    <li class="p-4"><div class="font-semibold">{{ $eventLabel($event->event_type) }}</div><div class="mt-1 text-xs text-base-content/55">{{ $event->occurred_at?->diffForHumans() }} · {{ str($event->source)->headline() }}</div></li>
                @empty
                    <li class="p-4 text-sm text-base-content/60">No updates recorded.</li>
                @endforelse
            </ol>
        </aside>
    </div>
    </div>

    @include('admin.milcom.partials.scripts')
@endsection

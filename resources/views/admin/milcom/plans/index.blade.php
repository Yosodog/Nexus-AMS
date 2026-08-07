@extends('layouts.admin')

@section('title', 'Milcom mass plans')

@php
    $plans = $plans ?? collect();
    $planRows = collect(is_object($plans) && method_exists($plans, 'items') ? $plans->items() : $plans)->take(50);
    $summary = $summary ?? [];
    $apiBase = $apiBase ?? url('/api/v1/milcom');
    $filters = $filters ?? request()->only(['search', 'status']);
    $relativeTime = static function ($value): string {
        if (is_object($value) && method_exists($value, 'diffForHumans')) {
            return $value->diffForHumans();
        }

        return filled($value) ? (string) $value : 'Not yet';
    };
    $statusPresentation = static function (string $status): array {
        $knownStatus = \App\Domain\Milcom\Enums\OperationStatus::tryFrom($status);

        return $knownStatus?->presentation() ?? [
            'label' => filled($status) ? str($status)->headline()->toString() : 'Unknown',
            'intent' => 'neutral',
            'icon' => 'minus-circle',
            'explanation' => 'This operation has an unrecognized legacy status.',
        ];
    };
    $stageLabel = static fn (string $stage): string => match ($stage) {
        'scope' => 'Setup',
        'objectives' => 'Targets',
        'staffing' => 'Teams',
        'dispatch' => 'Send rooms',
        default => str($stage)->headline()->toString(),
    };
@endphp

@section('content')
    <div
        data-milcom-app="plans-list"
        data-api-base="{{ $apiBase }}"
        data-resource-endpoint="{{ $apiBase }}/operations?type=plan&limit=50"
        class="contents"
    >
        <x-header title="Mass war plans" separator use-h1>
            <x-slot:subtitle>Choose targets, let Milcom assign teams, review any problems, then create one Discord room per target.</x-slot:subtitle>
            <x-slot:actions>
                <a href="{{ url('/admin/milcom/plans/create') }}" class="btn btn-primary hidden md:inline-flex">
                    <x-icon name="o-plus" class="size-5" aria-hidden="true" />
                    New plan
                </a>
            </x-slot:actions>
        </x-header>

        @include('admin.milcom.partials.navigation', ['milcomCurrent' => 'plans'])

        <section class="nexus-metrics" aria-label="Plan summary">
            <div class="nexus-metric">
                <span class="nexus-stat-label">In review</span>
                <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'review', 0)) }}</strong>
                <span class="nexus-stat-helper">Waiting for approval</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Critical coverage</span>
                <strong class="nexus-stat-value">{{ number_format((float) data_get($summary, 'critical_coverage_percent', 0), 0) }}%</strong>
                <span class="nexus-stat-helper">Targets with minimum staffing</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Unstaffed targets</span>
                <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'unstaffed', 0)) }}</strong>
                <span class="nexus-stat-helper">In open plans</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Member capacity used</span>
                <strong class="nexus-stat-value">{{ number_format((float) data_get($summary, 'member_utilization_percent', 0), 0) }}%</strong>
                <span class="nexus-stat-helper">Reserved offensive slots</span>
            </div>
        </section>

        <section class="nexus-panel" aria-labelledby="plan-operations-title">
            <div class="nexus-panel__header">
                <div>
                    <h2 id="plan-operations-title" class="nexus-section-title">Plans</h2>
                    <p class="mt-1 text-sm text-base-content/65">Open a plan to pick up where you left off.</p>
                </div>
                <form method="GET" action="{{ url('/admin/milcom/plans') }}" class="flex w-full flex-wrap gap-2 lg:w-auto" role="search">
                    <label class="input input-sm min-w-0 flex-1 lg:w-64">
                        <x-icon name="o-magnifying-glass" class="size-4 opacity-55" aria-hidden="true" />
                        <input type="search" name="search" value="{{ data_get($filters, 'search') }}" placeholder="Search plans" aria-label="Search plans">
                    </label>
                    <select name="status" class="select select-sm min-w-40" aria-label="Filter by status">
                        <option value="">All statuses</option>
                        @foreach (['draft', 'generating', 'review', 'dispatching', 'active', 'failed'] as $status)
                            <option value="{{ $status }}" @selected(data_get($filters, 'status') === $status)>{{ $statusPresentation($status)['label'] }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Apply</button>
                </form>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="table" data-sortable="false">
                    <thead>
                        <tr>
                            <th scope="col">Plan</th>
                            <th scope="col">Stage</th>
                            <th scope="col">Coverage</th>
                            <th scope="col">Needs attention</th>
                            <th scope="col">Updated</th>
                            <th scope="col" class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody data-milcom-resource-list aria-live="polite">
                        @forelse ($planRows as $plan)
                            @php
                                $planId = (int) data_get($plan, 'id', 0);
                                $status = (string) data_get($plan, 'status.value', data_get($plan, 'status', 'draft'));
                                $planStatusPresentation = $statusPresentation($status);
                                $stage = (string) data_get($plan, 'current_stage', 'scope');
                                $coverage = (float) data_get($plan, 'critical_coverage_percent', data_get($plan, 'coverage_percent', 0));
                            @endphp
                            <tr>
                                <td class="max-w-md whitespace-normal">
                                    <a href="{{ url('/admin/milcom/plans/'.$planId) }}" class="font-semibold link link-hover">{{ data_get($plan, 'name', 'Untitled plan') }}</a>
                                    <p class="mt-1 line-clamp-2 text-sm nexus-text-muted">{{ data_get($plan, 'scope_summary', 'Alliances and targets are not set yet.') }}</p>
                                </td>
                                <td>
                                    <x-nexus-status
                                        :label="$planStatusPresentation['label']"
                                        :intent="$planStatusPresentation['intent']"
                                        :icon="$planStatusPresentation['icon']"
                                    />
                                    <p class="mt-2 text-xs nexus-text-muted">{{ $stageLabel($stage) }}</p>
                                </td>
                                <td class="min-w-44">
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span>{{ number_format($coverage, 0) }}% critical</span>
                                        <span class="tabular-nums nexus-text-muted">{{ number_format((int) data_get($plan, 'staffed_objectives', 0)) }}/{{ number_format((int) data_get($plan, 'objective_count', 0)) }}</span>
                                    </div>
                                    <progress class="progress progress-primary mt-2 h-1.5 w-full" value="{{ min(100, max(0, $coverage)) }}" max="100" aria-label="Critical coverage {{ number_format($coverage, 0) }} percent"></progress>
                                </td>
                                <td>
                                    @php
                                        $attention = (int) data_get($plan, 'attention_count', 0);
                                    @endphp
                                    <span class="badge {{ $attention > 0 ? 'badge-warning badge-soft' : 'badge-ghost' }}">{{ number_format($attention) }} flagged</span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span>{{ $relativeTime(data_get($plan, 'updated_at')) }}</span>
                                    <span class="block text-xs nexus-text-muted">v{{ number_format((int) data_get($plan, 'generation_version', 1)) }}</span>
                                </td>
                                <td class="text-right">
                                    <a href="{{ url('/admin/milcom/plans/'.$planId) }}" class="btn btn-outline btn-sm">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="nexus-empty-state">
                                        <x-icon name="o-map" class="size-9 nexus-text-muted" aria-hidden="true" />
                                        <div>
                                            <h3 class="text-lg font-semibold">No plans match these filters</h3>
                                            <p class="mt-1 text-sm text-base-content/65">Create a plan or clear the filters.</p>
                                        </div>
                                        <a href="{{ url('/admin/milcom/plans/create') }}" class="btn btn-primary btn-sm">Create plan</a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-base-300 md:hidden" data-milcom-mobile-resource-list>
                @forelse ($planRows as $plan)
                    @php
                        $planId = (int) data_get($plan, 'id', 0);
                        $status = (string) data_get($plan, 'status.value', data_get($plan, 'status', 'draft'));
                        $planStatusPresentation = $statusPresentation($status);
                        $coverage = (float) data_get($plan, 'critical_coverage_percent', data_get($plan, 'coverage_percent', 0));
                    @endphp
                    <a href="{{ url('/admin/milcom/plans/'.$planId) }}" class="block p-4 transition-colors hover:bg-base-200/60 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="truncate font-semibold">{{ data_get($plan, 'name', 'Untitled plan') }}</h3>
                                <p class="mt-1 text-xs nexus-text-muted">Updated {{ $relativeTime(data_get($plan, 'updated_at')) }}</p>
                            </div>
                            <x-nexus-status
                                :label="$planStatusPresentation['label']"
                                :intent="$planStatusPresentation['intent']"
                                :icon="$planStatusPresentation['icon']"
                            />
                        </div>
                        <div class="mt-4 flex items-center justify-between gap-3 text-sm">
                            <span>{{ number_format($coverage, 0) }}% critical coverage</span>
                            <span>{{ number_format((int) data_get($plan, 'attention_count', 0)) }} flagged</span>
                        </div>
                        <progress class="progress progress-primary mt-2 h-1.5 w-full" value="{{ min(100, max(0, $coverage)) }}" max="100"></progress>
                    </a>
                @empty
                    <div class="nexus-empty-state">
                        <x-icon name="o-map" class="size-9 nexus-text-muted" aria-hidden="true" />
                        <div>
                            <h3 class="font-semibold">No plans found</h3>
                            <p class="mt-1 text-sm text-base-content/65">Create a plan when you are ready to choose targets.</p>
                        </div>
                    </div>
                @endforelse
            </div>

            @if (is_object($plans) && method_exists($plans, 'hasPages') && $plans->hasPages())
                <div class="nexus-panel__footer">{{ $plans->links() }}</div>
            @endif
        </section>

        <p class="sr-only" role="status" aria-live="polite" data-milcom-status></p>
    </div>

    @include('admin.milcom.partials.scripts')
@endsection

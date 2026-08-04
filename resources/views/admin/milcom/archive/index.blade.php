@extends('layouts.admin')

@section('title', 'Milcom archive')

@php
    $activeTab = (string) ($activeTab ?? request('tab', 'v2'));
    $legacyType = (string) ($legacyType ?? request('legacy_type', 'plans'));
    $archivedOperations = $archivedOperations ?? collect();
    $legacyPlans = $legacyPlans ?? collect();
    $legacyCounters = $legacyCounters ?? collect();
    $v2Rows = collect(is_object($archivedOperations) && method_exists($archivedOperations, 'items') ? $archivedOperations->items() : $archivedOperations)->take(50);
    $legacySource = $legacyType === 'counters' ? $legacyCounters : $legacyPlans;
    $legacyRows = collect(is_object($legacySource) && method_exists($legacySource, 'items') ? $legacySource->items() : $legacySource)->take(50);
    $summary = $summary ?? [];
    $apiBase = $apiBase ?? url('/api/v1/milcom');
    $relativeTime = static function ($value): string {
        if (is_object($value) && method_exists($value, 'diffForHumans')) {
            return $value->diffForHumans();
        }

        return filled($value) ? (string) $value : 'Unknown';
    };
@endphp

@section('content')
    <div data-milcom-app="archive" data-api-base="{{ $apiBase }}" class="contents">
        <x-header title="Milcom archive" separator use-h1>
            <x-slot:subtitle>Past plans and counters are view only. New and older records stay separate.</x-slot:subtitle>
        </x-header>

        @include('admin.milcom.partials.navigation', ['milcomCurrent' => 'archive'])

        <section class="nexus-metrics" aria-label="Milcom archive summary">
            <div class="nexus-metric">
                <span class="nexus-stat-label">Milcom v2</span>
                <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'v2_operations', 0)) }}</strong>
                <span class="nexus-stat-helper">Completed or archived</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Legacy plans</span>
                <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'legacy_plans', 0)) }}</strong>
                <span class="nexus-stat-helper">Kept permanently</span>
            </div>
            <div class="nexus-metric">
                <span class="nexus-stat-label">Legacy counters</span>
                <strong class="nexus-stat-value">{{ number_format((int) data_get($summary, 'legacy_counters', 0)) }}</strong>
                <span class="nexus-stat-helper">Kept permanently</span>
            </div>
        </section>

        <div class="border-b border-base-300" role="tablist" aria-label="Archive source">
            <div class="flex gap-1">
                <a
                    href="{{ url('/admin/milcom/archive?tab=v2') }}"
                    role="tab"
                    aria-selected="{{ $activeTab === 'v2' ? 'true' : 'false' }}"
                    class="btn rounded-b-none border-b-0 {{ $activeTab === 'v2' ? 'bg-base-100 text-primary' : 'btn-ghost text-base-content/65' }}"
                    data-milcom-tab="v2"
                >
                    V2 archive
                </a>
                <a
                    href="{{ url('/admin/milcom/archive?tab=legacy') }}"
                    role="tab"
                    aria-selected="{{ $activeTab === 'legacy' ? 'true' : 'false' }}"
                    class="btn rounded-b-none border-b-0 {{ $activeTab === 'legacy' ? 'bg-base-100 text-primary' : 'btn-ghost text-base-content/65' }}"
                    data-milcom-tab="legacy"
                >
                    Legacy history
                </a>
            </div>
        </div>

        @if ($activeTab === 'legacy')
            <section class="nexus-panel" role="tabpanel" aria-label="Legacy history">
                <div class="nexus-panel__header">
                    <div>
                        <h2 class="nexus-section-title">Legacy history</h2>
                        <p class="mt-1 max-w-3xl text-sm text-base-content/65">These records were archived when v2 launched. They stay separate from v2 and cannot be changed or sent again.</p>
                    </div>
                    <div class="join" aria-label="Legacy record type">
                        <a href="{{ url('/admin/milcom/archive?tab=legacy&legacy_type=plans') }}" class="btn btn-sm join-item {{ $legacyType === 'plans' ? 'btn-primary' : 'btn-outline' }}">Plans</a>
                        <a href="{{ url('/admin/milcom/archive?tab=legacy&legacy_type=counters') }}" class="btn btn-sm join-item {{ $legacyType === 'counters' ? 'btn-primary' : 'btn-outline' }}">Counters</a>
                    </div>
                </div>

                <div class="alert alert-info m-4 items-start" role="note">
                    <x-icon name="o-lock-closed" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                    <div><div class="font-semibold">Read only</div><p class="text-sm">Details load when you open a record, which keeps the archive fast.</p></div>
                </div>

                <div class="overflow-x-auto">
                    <table class="table" data-sortable="false">
                        <thead>
                            <tr>
                                <th scope="col">{{ $legacyType === 'counters' ? 'Aggressor' : 'Plan' }}</th>
                                <th scope="col">Final status</th>
                                <th scope="col">Size</th>
                                <th scope="col">Archived</th>
                                <th scope="col" class="text-right">History</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($legacyRows as $record)
                                @php
                                    $recordId = (int) data_get($record, 'id', 0);
                                    $recordLabel = $legacyType === 'counters'
                                        ? data_get($record, 'aggressor.nation_name', data_get($record, 'aggressor_name', 'Legacy counter'))
                                        : data_get($record, 'name', 'Legacy plan');
                                @endphp
                                <tr data-milcom-legacy-row>
                                    <td class="max-w-md whitespace-normal">
                                        <div class="font-semibold">
                                            @if ($legacyType === 'counters')
                                                <x-pw-nation-link :nation-id="data_get($record, 'aggressor.id')" :label="$recordLabel" />
                                            @else
                                                {{ $recordLabel }}
                                            @endif
                                        </div>
                                        <p class="mt-1 text-xs text-base-content/55">Legacy #{{ number_format($recordId) }}</p>
                                    </td>
                                    <td><span class="badge badge-ghost">{{ str((string) data_get($record, 'status', 'archived'))->headline() }}</span></td>
                                    <td class="whitespace-nowrap tabular-nums">
                                        @if ($legacyType === 'counters')
                                            {{ number_format((int) data_get($record, 'assignments_count', data_get($record, 'team_size', 0))) }} assignments
                                        @else
                                            {{ number_format((int) data_get($record, 'targets_count', 0)) }} targets · {{ number_format((int) data_get($record, 'assignments_count', 0)) }} assignments
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">{{ $relativeTime(data_get($record, 'archived_at', data_get($record, 'updated_at'))) }}</td>
                                    <td class="text-right">
                                        <a
                                            href="{{ url('/admin/milcom/archive/legacy/'.$legacyType.'/'.$recordId) }}"
                                            class="btn btn-outline btn-sm"
                                            data-milcom-load-legacy
                                            data-detail-endpoint="{{ $apiBase }}/archive/legacy/{{ $legacyType }}/{{ $recordId }}"
                                        >View details</a>
                                    </td>
                                </tr>
                                <tr class="hidden" data-milcom-legacy-detail-row>
                                    <td colspan="5" class="bg-base-200/60 p-4" data-milcom-legacy-detail></td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><div class="nexus-empty-state"><x-icon name="o-archive-box" class="size-9 text-base-content/35" aria-hidden="true" /><div><h3 class="font-semibold">No legacy {{ $legacyType }} are archived</h3><p class="mt-1 text-sm text-base-content/65">Legacy records stay available here after they are archived.</p></div></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (is_object($legacySource) && method_exists($legacySource, 'hasPages') && $legacySource->hasPages())
                    <div class="nexus-panel__footer">{{ $legacySource->links() }}</div>
                @endif
            </section>
        @else
            <section class="nexus-panel" role="tabpanel" aria-label="V2 archive">
                <div class="nexus-panel__header">
                    <div>
                        <h2 class="nexus-section-title">Milcom v2 records</h2>
                        <p class="mt-1 text-sm text-base-content/65">Completed plans and counters with final coverage, Discord rooms, and results.</p>
                    </div>
                    <form method="GET" action="{{ url('/admin/milcom/archive') }}" class="flex w-full gap-2 sm:w-auto" role="search">
                        <input type="hidden" name="tab" value="v2">
                        <label class="input input-sm min-w-0 flex-1 sm:w-64"><x-icon name="o-magnifying-glass" class="size-4 opacity-55" aria-hidden="true" /><input type="search" name="search" value="{{ request('search') }}" placeholder="Search archive" aria-label="Search archived records"></label>
                        <button type="submit" class="btn btn-outline btn-sm">Search</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="table" data-sortable="false">
                        <thead>
                            <tr><th scope="col">Plan or counter</th><th scope="col">Type</th><th scope="col">Outcome</th><th scope="col">Coverage</th><th scope="col">Archived</th><th scope="col" class="text-right">History</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($v2Rows as $operation)
                                @php
                                    $operationId = (int) data_get($operation, 'id', 0);
                                    $operationType = (string) data_get($operation, 'type.value', data_get($operation, 'type', 'plan'));
                                @endphp
                                <tr>
                                    <td class="max-w-md whitespace-normal"><div class="font-semibold">{{ data_get($operation, 'name', str($operationType)->headline()) }}</div><p class="mt-1 text-xs text-base-content/55">{{ number_format((int) data_get($operation, 'objective_count', 0)) }} targets · {{ number_format((int) data_get($operation, 'assignment_count', 0)) }} assigned nations</p></td>
                                    <td><span class="badge badge-outline">{{ str($operationType)->headline() }}</span></td>
                                    <td><span class="badge badge-success badge-soft">{{ str((string) data_get($operation, 'status.value', data_get($operation, 'status', 'completed')))->headline() }}</span></td>
                                    <td class="tabular-nums">{{ number_format((float) data_get($operation, 'final_coverage_percent', 0), 0) }}%</td>
                                    <td class="whitespace-nowrap">{{ $relativeTime(data_get($operation, 'archived_at', data_get($operation, 'completed_at'))) }}</td>
                                    <td class="text-right"><a href="{{ url('/admin/milcom/archive/'.$operationId) }}" class="btn btn-outline btn-sm">View history</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="6"><div class="nexus-empty-state"><x-icon name="o-archive-box" class="size-9 text-base-content/35" aria-hidden="true" /><div><h3 class="font-semibold">No Milcom v2 records yet</h3><p class="mt-1 text-sm text-base-content/65">Completed plans and counters appear here after their final check.</p></div></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (is_object($archivedOperations) && method_exists($archivedOperations, 'hasPages') && $archivedOperations->hasPages())
                    <div class="nexus-panel__footer">{{ $archivedOperations->links() }}</div>
                @endif
            </section>
        @endif

        <div class="hidden alert alert-error items-start" role="alert" data-milcom-feedback><x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" /><div><div class="font-semibold" data-milcom-feedback-title>Could not load history</div><p class="text-sm" data-milcom-feedback-message>Open the full record instead. It is read only.</p></div></div>
        <p class="sr-only" role="status" aria-live="polite" data-milcom-status></p>
    </div>

    @include('admin.milcom.partials.scripts')
@endsection

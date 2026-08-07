@php
    $syncRunning = collect([$nationBatch, $rollingNationBatch, $allianceBatch, $warBatch])
        ->filter()
        ->contains(fn ($batch) => ! $batch->finished());
@endphp

<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="data-synchronization"
        title="Data Synchronization"
        description="Review rolling progress and run manual nation, alliance, or war synchronization when data is known to be stale."
        :status="$syncRunning ? 'Sync running' : 'Manual controls'"
        :status-class="$syncRunning ? 'badge-info' : 'badge-ghost'"
        open
    >
        <div class="space-y-6">
            <div class="alert alert-info">
                <div class="space-y-2 text-sm leading-6">
                    <p>{{ config('app.name') }} normally stays current through live Politics & War subscriptions and scheduled full syncs. Manual execution is rarely needed.</p>
                    <p><strong>Queue impact:</strong> a full sync can take from several minutes to nearly an hour and may delay withdrawals, transfers, and in-game messaging.</p>
                </div>
            </div>

            <div class="grid gap-5 xl:grid-cols-2">
                @include('components.admin.sync-card', [
                    'title' => 'Nation Sync (Manual)',
                    'batch' => $nationBatch,
                    'route' => route('admin.settings.sync.run'),
                ])

                @include('components.admin.rolling-sync-card', [
                    'batch' => $rollingNationBatch,
                    'rollingSchedule' => $rollingSchedule,
                ])

                @include('components.admin.sync-card', [
                    'title' => 'Alliance Sync',
                    'batch' => $allianceBatch,
                    'route' => route('admin.settings.sync.alliances'),
                ])

                @include('components.admin.sync-card', [
                    'title' => 'War Sync',
                    'batch' => $warBatch,
                    'route' => route('admin.settings.sync.wars'),
                ])
            </div>
        </div>
    </x-admin.settings-disclosure>
</div>

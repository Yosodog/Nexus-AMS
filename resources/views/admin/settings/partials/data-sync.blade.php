@php
    $syncRunning = collect([$nationBatch, $rollingNationBatch, $allianceBatch, $warBatch])
        ->filter()
        ->contains(fn ($batch) => ! $batch->finished());
@endphp

<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="data-synchronization"
        title="Data synchronization"
        description="Review recent updates or start a manual nation, alliance, or war sync when the displayed data is out of date."
        :status="$syncRunning ? 'Sync running' : 'Manual controls'"
        :status-class="$syncRunning ? 'badge-info' : 'badge-ghost'"
        open
    >
        <div class="space-y-6">
            <div class="alert alert-info">
                <div class="space-y-2 text-sm leading-6">
                    <p>{{ config('app.name') }} normally stays current through Politics & War updates and scheduled full syncs. You should rarely need to start one manually.</p>
                    <p><strong>While it runs:</strong> a full sync may take several minutes or nearly an hour. Withdrawals, transfers, and in-game messages may be delayed.</p>
                </div>
            </div>

            <div class="grid gap-5 xl:grid-cols-2">
                @include('components.admin.sync-card', [
                    'title' => 'Nation sync (manual)',
                    'batch' => $nationBatch,
                    'route' => route('admin.settings.sync.run'),
                ])

                @include('components.admin.rolling-sync-card', [
                    'batch' => $rollingNationBatch,
                    'rollingSchedule' => $rollingSchedule,
                ])

                @include('components.admin.sync-card', [
                    'title' => 'Alliance sync',
                    'batch' => $allianceBatch,
                    'route' => route('admin.settings.sync.alliances'),
                ])

                @include('components.admin.sync-card', [
                    'title' => 'War sync',
                    'batch' => $warBatch,
                    'route' => route('admin.settings.sync.wars'),
                ])
            </div>
        </div>
    </x-admin.settings-disclosure>
</div>

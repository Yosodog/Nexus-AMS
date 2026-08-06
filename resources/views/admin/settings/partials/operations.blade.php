@php
    $syncRunning = collect([$nationBatch, $rollingNationBatch, $allianceBatch, $warBatch])
        ->filter()
        ->contains(fn ($batch) => ! $batch->finished());
    $stalePendingCount = collect($pendingRecoveryItems)->sum('stalePending');
@endphp

<div class="mb-5">
    <h2 class="nexus-section-title">Operations</h2>
    <p class="mt-1 max-w-3xl text-sm text-base-content/70">
        Data synchronization, backups, retention, workflow recovery, and health diagnostics for the application.
    </p>
</div>

<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="data-synchronization"
        title="Data Synchronization"
        description="Review rolling progress and run manual nation, alliance, or war synchronization when data is known to be stale."
        :status="$syncRunning ? 'Sync running' : 'Manual controls'"
        :status-class="$syncRunning ? 'badge-info' : 'badge-ghost'"
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

    <x-admin.settings-disclosure
        id="backup-settings"
        title="Backups"
        description="Run configured application and database backups every six hours and review recovery prerequisites."
        :status="$backupsEnabled ? 'Enabled' : 'Disabled'"
        :status-class="$backupsEnabled ? 'badge-success' : 'badge-ghost'"
    >
        <div class="space-y-5">
            <dl class="grid gap-x-6 gap-y-4 border-y border-base-300 py-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs font-semibold text-base-content/60">Destinations</dt>
                    <dd class="mt-1 font-semibold">{{ implode(', ', $backupDisks) ?: 'None' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-base-content/60">Archive verification</dt>
                    <dd class="mt-1 font-semibold">{{ $backupVerificationEnabled ? 'Enabled' : 'Disabled' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-base-content/60">Failure alerts</dt>
                    <dd class="mt-1 font-semibold">{{ $backupFailureAlertsEnabled ? 'Configured' : 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-base-content/60">Archive password</dt>
                    <dd class="mt-1 font-semibold">{{ $backupArchivePasswordConfigured ? 'Configured' : 'Not configured' }}</dd>
                </div>
            </dl>

            @if (! $backupFailureAlertsEnabled || ! $backupArchivePasswordConfigured)
                <div class="alert alert-warning text-sm">
                    Configure a notification address and archive password before relying on these backups for production recovery.
                </div>
            @endif

            <form method="POST" action="{{ route('admin.settings.backups') }}" class="max-w-2xl space-y-5">
                @csrf
                <input type="hidden" name="backups_enabled" value="0">

                <label class="flex cursor-pointer items-start gap-3">
                    <input class="toggle toggle-primary mt-0.5" type="checkbox" id="backupsEnabled" name="backups_enabled" value="1" @checked(old('backups_enabled', $backupsEnabled))>
                    <span>
                        <span class="block font-semibold">Enable scheduled backups</span>
                        <span class="mt-1 block text-sm text-base-content/70">The configured backup job runs every six hours while enabled.</span>
                    </span>
                </label>

                <button class="btn btn-primary" type="submit">Save backup setting</button>
            </form>
        </div>
    </x-admin.settings-disclosure>

    <x-admin.settings-disclosure
        id="audit-retention"
        title="Audit Log Retention"
        description="Choose how long persisted audit log records remain available."
        :status="$auditRetentionDays . ' days'"
        :open="$errors->has('audit_log_retention_days')"
    >
        <form method="POST" action="{{ route('admin.settings.audit-retention') }}" class="max-w-2xl space-y-5">
            @csrf

            <label class="block max-w-sm space-y-2">
                <span class="text-sm font-medium">Retention period (days)</span>
                <input type="number" class="input w-full" id="auditRetentionDays" name="audit_log_retention_days" min="1" max="3650" value="{{ old('audit_log_retention_days', $auditRetentionDays) }}" required>
                <span class="text-xs text-base-content/60">Choose from 1 to 3650 days (up to 10 years).</span>
            </label>

            <button class="btn btn-primary" type="submit">Save audit retention</button>
        </form>
    </x-admin.settings-disclosure>

    <x-admin.settings-disclosure
        id="pending-request-recovery"
        title="Pending Request Recovery"
        description="Force genuinely stuck pending workflow rows into a terminal state without approving them."
        :status="$stalePendingCount > 0 ? number_format($stalePendingCount) . ' stale' : 'No stale rows'"
        :status-class="$stalePendingCount > 0 ? 'badge-warning' : 'badge-ghost'"
        class="bg-warning/5"
        :open="$errors->hasAny(['type', 'older_than_hours'])"
    >
        <div class="space-y-5">
            <div class="alert alert-warning">
                <span class="text-sm">Recovery actions do not approve anything. Matching rows are moved to a terminal state such as denied, cancelled, or expired.</span>
            </div>

            <div class="overflow-x-auto rounded-box border border-base-300">
                <table class="table table-zebra" data-sortable="false">
                    <thead>
                    <tr>
                        <th scope="col">Workflow</th>
                        <th scope="col">Pending</th>
                        <th scope="col">Stale ({{ $stalePendingDefaultHours }}h+)</th>
                        <th scope="col">Oldest pending</th>
                        <th scope="col">Recovery action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($pendingRecoveryItems as $item)
                        <tr>
                            <td class="font-semibold">{{ $item['label'] }}</td>
                            <td>{{ number_format($item['totalPending']) }}</td>
                            <td>
                                <span class="badge {{ $item['stalePending'] > 0 ? 'badge-warning' : 'badge-ghost' }}">
                                    {{ number_format($item['stalePending']) }}
                                </span>
                            </td>
                            <td>
                                @if ($item['oldestCreatedAt'])
                                    <div>{{ $item['oldestCreatedAt']->format('M d, Y H:i') }}</div>
                                    <div class="text-sm text-base-content/60">{{ $item['oldestCreatedAt']->diffForHumans() }}</div>
                                @else
                                    <span class="text-sm text-base-content/60">None</span>
                                @endif
                            </td>
                            <td>
                                <form
                                    method="POST"
                                    action="{{ route('admin.settings.pending-requests.release-stale') }}"
                                    class="flex flex-wrap items-end gap-2"
                                    data-confirm="Release stale {{ strtolower($item['label']) }} rows? Matching requests will be moved to a terminal state."
                                    data-confirm-title="Release stale requests?"
                                    data-confirm-label="Release stale"
                                    data-confirm-tone="warning"
                                >
                                    @csrf
                                    <input type="hidden" name="type" value="{{ $item['type'] }}">
                                    <label class="block space-y-2">
                                        <span class="text-xs font-medium">Older than (hours)</span>
                                        <input
                                            type="number"
                                            class="input input-sm w-28"
                                            id="olderThanHours-{{ $item['type'] }}"
                                            name="older_than_hours"
                                            min="1"
                                            max="8760"
                                            value="{{ old('older_than_hours', $stalePendingDefaultHours) }}"
                                            required
                                        >
                                    </label>
                                    <button class="btn btn-warning btn-outline btn-sm" type="submit">Release stale</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </x-admin.settings-disclosure>
</div>

@include('components.admin.system-health', ['health' => $systemHealth])

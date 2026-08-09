<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="backup-settings"
        title="Backups"
        description="Run application and database backups every six hours and review what is needed to restore them."
        :status="$backupsEnabled ? 'Enabled' : 'Disabled'"
        :status-class="$backupsEnabled ? 'badge-success' : 'badge-ghost'"
    >
        <div class="space-y-5">
            <dl class="grid gap-x-6 gap-y-4 border-y border-base-300 py-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm font-semibold text-base-content/70">Destinations</dt>
                    <dd class="mt-1 font-semibold">{{ implode(', ', $backupDisks) ?: 'None' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-semibold text-base-content/70">Backup check</dt>
                    <dd class="mt-1 font-semibold">{{ $backupVerificationEnabled ? 'Enabled' : 'Disabled' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-semibold text-base-content/70">Failure alerts</dt>
                    <dd class="mt-1 font-semibold">{{ $backupFailureAlertsEnabled ? 'Configured' : 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-semibold text-base-content/70">Archive password</dt>
                    <dd class="mt-1 font-semibold">{{ $backupArchivePasswordConfigured ? 'Configured' : 'Not configured' }}</dd>
                </div>
            </dl>

            @if (! $backupFailureAlertsEnabled || ! $backupArchivePasswordConfigured)
                <div class="alert alert-warning text-sm">
                    Configure a notification address and archive password before relying on these backups to restore the live site.
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
        title="Audit log retention"
        description="Choose how long audit records remain available."
        :status="$auditRetentionDays . ' days'"
        :open="$errors->has('audit_log_retention_days')"
    >
        <form method="POST" action="{{ route('admin.settings.audit-retention') }}" class="max-w-2xl space-y-5">
            @csrf

            <label class="block max-w-sm space-y-2">
                <span class="text-sm font-medium">Retention period (days)</span>
                <input type="number" class="input w-full" id="auditRetentionDays" name="audit_log_retention_days" min="1" max="3650" value="{{ old('audit_log_retention_days', $auditRetentionDays) }}" required>
                <span class="text-sm text-base-content/70">Choose from 1 to 3650 days (up to 10 years).</span>
            </label>

            <button class="btn btn-primary" type="submit">Save retention setting</button>
        </form>
    </x-admin.settings-disclosure>
</div>

@php
    $backupStateLabels = [
        'healthy' => ['Healthy', 'badge-success'],
        'overdue' => ['Overdue', 'badge-warning'],
        'failing' => ['Failing', 'badge-error'],
        'never' => ['No backups yet', 'badge-warning'],
        'disabled' => ['Schedule off', 'badge-ghost'],
    ];
    [$backupStateLabel, $backupStateClass] = $backupStateLabels[$backupHealth['state']];
    $backupSecretSourceLabels = ['admin' => 'Saved here', 'env' => 'From .env', 'none' => 'Not set'];
    $backupFormErrors = ['schedule_preset', 'schedule_cron', 'destination', 's3_bucket', 's3_region', 's3_endpoint', 's3_folder', 's3_access_key_id', 's3_secret_access_key', 'archive_password', 'backup_check', 'backup_run'];
    $formatBytes = fn (?int $bytes): string => $bytes === null ? '—' : \Illuminate\Support\Number::fileSize($bytes, precision: 1);
@endphp
<div class="nexus-panel divide-y divide-base-300 overflow-hidden">
    <x-admin.settings-disclosure
        id="backup-settings"
        title="Backups"
        description="Schedule database backups, choose where they are stored, and check that the last one worked."
        :status="$backupStateLabel"
        :status-class="$backupStateClass"
        :open="$errors->hasAny($backupFormErrors) || session()->has('backup-check-results')"
    >
        <div class="space-y-6">
            <section class="space-y-4" aria-labelledby="backup-health-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 id="backup-health-heading" class="flex items-center gap-2 font-semibold">
                        Backup health <span class="badge {{ $backupStateClass }}">{{ $backupStateLabel }}</span>
                    </h3>

                    <form method="POST" action="{{ route('admin.settings.backups.run') }}" data-confirm="Start a backup now? It dumps the full database and usually takes a few minutes." data-confirm-title="Run a backup now?" data-confirm-label="Run backup">
                        @csrf
                        <button class="btn btn-primary btn-sm" type="submit" @disabled($backupHealth['active'] !== null)>Run backup now</button>
                    </form>
                </div>

                @error('backup_run')
                    <div class="alert alert-warning text-sm">{{ $message }}</div>
                @enderror

                @if ($backupHealth['state'] === 'failing')
                    <div class="alert alert-error text-sm">
                        The last backup failed: {{ $backupHealth['lastFailure']?->error_message ?? 'no error was recorded.' }}
                    </div>
                @elseif ($backupHealth['state'] === 'overdue')
                    <div class="alert alert-warning text-sm">
                        No backup has succeeded since the last scheduled run. Check that the scheduler is running.
                    </div>
                @endif

                <dl class="grid gap-x-6 gap-y-4 border-y border-base-300 py-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-sm font-semibold text-base-content/70">Last successful backup</dt>
                        <dd class="mt-1 font-semibold">
                            @if ($backupHealth['lastSuccess'])
                                {{ $backupHealth['lastSuccess']->finished_at?->diffForHumans() }}
                                <span class="block text-sm font-normal text-base-content/70">{{ $formatBytes($backupHealth['lastSuccess']->backup_size_bytes) }}</span>
                            @else
                                None recorded
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-base-content/70">Next scheduled run</dt>
                        <dd class="mt-1 font-semibold">
                            @if ($backupHealth['nextRunAt'])
                                {{ $backupHealth['nextRunAt']->format('M d, H:i') }} UTC
                                <span class="block text-sm font-normal text-base-content/70">{{ $backupHealth['nextRunAt']->diffForHumans() }}</span>
                            @else
                                Schedule is off
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-base-content/70">Current run</dt>
                        <dd class="mt-1 font-semibold">
                            @if ($backupHealth['active'])
                                {{ $backupHealth['active']->status->label() }}
                                <span class="block text-sm font-normal text-base-content/70">since {{ ($backupHealth['active']->started_at ?? $backupHealth['active']->created_at)->diffForHumans() }}</span>
                            @else
                                Idle
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-base-content/70">Failure alerts</dt>
                        <dd class="mt-1 font-semibold">{{ $backupFailureAlertsEnabled ? 'Email configured' : 'Not configured' }}</dd>
                    </div>
                </dl>

                @if ($backupHealth['recent']->isNotEmpty())
                    <div class="overflow-x-auto rounded-box border border-base-300">
                        <table class="table table-zebra table-sm" data-sortable="false">
                            <thead>
                            <tr>
                                <th scope="col">Started</th>
                                <th scope="col">Trigger</th>
                                <th scope="col">Status</th>
                                <th scope="col">Size</th>
                                <th scope="col">Details</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($backupHealth['recent'] as $run)
                                <tr>
                                    <td class="whitespace-nowrap">{{ ($run->started_at ?? $run->created_at)->format('M d, H:i') }} UTC</td>
                                    <td>
                                        {{ $run->trigger->label() }}
                                        @if ($run->requestedBy)
                                            <span class="block text-sm text-base-content/70">{{ $run->requestedBy->name }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span @class([
                                            'badge',
                                            'badge-success' => $run->status === \App\Enums\BackupRunStatus::Succeeded,
                                            'badge-error' => $run->status === \App\Enums\BackupRunStatus::Failed,
                                            'badge-info' => $run->status->isActive(),
                                            'badge-ghost' => $run->status === \App\Enums\BackupRunStatus::Cancelled,
                                        ])>{{ $run->status->label() }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">{{ $formatBytes($run->backup_size_bytes) }}</td>
                                    <td class="max-w-md text-sm break-words">{{ $run->error_message ?? $run->backup_path ?? '' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <form method="POST" action="{{ route('admin.settings.backups') }}" class="max-w-2xl space-y-4 border-t border-base-300 pt-5">
                @csrf
                <input type="hidden" name="backups_enabled" value="0">

                <label class="flex cursor-pointer items-start gap-3">
                    <input class="toggle toggle-primary mt-0.5" type="checkbox" id="backupsEnabled" name="backups_enabled" value="1" @checked(old('backups_enabled', $backupsEnabled))>
                    <span>
                        <span class="block font-semibold">Enable scheduled backups</span>
                        <span class="mt-1 block text-sm text-base-content/70">Runs on the schedule below (<code>{{ $backupConfiguration->scheduleCron }}</code>, UTC). "Run backup now" works either way.</span>
                    </span>
                </label>

                <button class="btn btn-primary" type="submit">Save backup setting</button>
            </form>

            <form method="POST" action="{{ route('admin.settings.backups.configuration') }}" class="max-w-3xl space-y-6 border-t border-base-300 pt-5" autocomplete="off">
                @csrf

                <div class="space-y-1">
                    <h3 class="font-semibold">Schedule and storage</h3>
                    <p class="text-sm text-base-content/70">
                        Saving checks the destination with a test file before anything changes; no backup runs.
                        {{ $backupConfigurationSavedInAdmin ? 'These settings are saved here and override .env.' : 'Showing the current .env values; saving here overrides them.' }}
                    </p>
                </div>

                @error('backup_check')
                    <div class="alert alert-error text-sm">
                        <div>
                            <p class="font-semibold">Nothing was saved because a check failed:</p>
                            <ul class="mt-1 list-disc pl-5">
                                @foreach ($errors->get('backup_check') as $failure)
                                    <li>{{ $failure }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @enderror

                @if (session('backup-check-results'))
                    <ul class="space-y-1 text-sm" aria-label="Connection check results">
                        @foreach (session('backup-check-results') as $result)
                            <li class="flex items-start gap-2">
                                <x-icon :name="$result['passed'] ? 'o-check-circle' : 'o-x-circle'" :class="'mt-0.5 size-4 shrink-0 '.($result['passed'] ? 'text-success' : 'text-error')" aria-hidden="true" />
                                <span><span class="font-semibold">{{ $result['check'] }}:</span> {{ $result['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Schedule</span>
                        <select class="select w-full" name="schedule_preset" id="backupSchedulePreset">
                            @foreach (['every_6_hours' => 'Every 6 hours', 'every_12_hours' => 'Every 12 hours', 'daily' => 'Once a day', 'custom' => 'Custom cron'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('schedule_preset', $backupSchedulePreset) === $value)>
                                    {{ $label }}{{ isset($backupSchedulePresets[$value]) ? ' ('.$backupSchedulePresets[$value].')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('schedule_preset') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Custom cron (UTC)</span>
                        <input type="text" class="input w-full font-mono" name="schedule_cron" id="backupScheduleCron" value="{{ old('schedule_cron', $backupSchedulePreset === 'custom' ? $backupConfiguration->scheduleCron : '') }}" maxlength="100" placeholder="30 1 * * *">
                        <span class="block text-sm text-base-content/70">Used only when Schedule is "Custom cron".</span>
                        @error('schedule_cron') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                    </label>

                    <label class="block space-y-2 sm:col-span-2">
                        <span class="text-sm font-medium">Store backups on</span>
                        <select class="select w-full" name="destination" id="backupDestination">
                            @foreach ($backupDestinations as $destination)
                                <option value="{{ $destination->value }}" @selected(old('destination', $backupConfiguration->destination->value) === $destination->value)>{{ $destination->label() }}</option>
                            @endforeach
                        </select>
                        <span class="block text-sm text-base-content/70">Keeping backups only on this server does not protect against losing the server.</span>
                        @error('destination') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                    </label>
                </div>

                <fieldset class="space-y-4 rounded-box border border-base-300 p-4">
                    <legend class="px-1 text-sm font-semibold">S3 bucket</legend>
                    <p class="text-sm text-base-content/70">Used when backups go to S3. Works with AWS S3 and S3-compatible storage such as DigitalOcean Spaces, Cloudflare R2 and Backblaze B2.</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Bucket name</span>
                            <input type="text" class="input w-full" name="s3_bucket" id="backupS3Bucket" value="{{ old('s3_bucket', $backupConfiguration->s3Bucket) }}" maxlength="63" placeholder="my-backups">
                            @error('s3_bucket') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Region</span>
                            <input type="text" class="input w-full" name="s3_region" id="backupS3Region" value="{{ old('s3_region', $backupConfiguration->s3Region) }}" maxlength="40" placeholder="us-east-1">
                            @error('s3_region') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                        </label>

                        <label class="block space-y-2 sm:col-span-2">
                            <span class="text-sm font-medium">Folder (optional)</span>
                            <input type="text" class="input w-full" name="s3_folder" id="backupS3Folder" value="{{ old('s3_folder', $backupConfiguration->s3Folder) }}" maxlength="200" placeholder="nexus">
                            <span class="block text-sm text-base-content/70">Backups are written to <code>{{ trim(($backupConfiguration->s3Folder ? $backupConfiguration->s3Folder.'/' : '').$backupFolderName, '/') }}/</code> in the bucket. The key needs access to that path.</span>
                            @error('s3_folder') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Access key ID <span class="badge badge-ghost badge-sm">{{ $backupSecretSourceLabels[$backupSecretSources[\App\Services\Backups\BackupSettings::S3_ACCESS_KEY_ID]] }}</span></span>
                            <input type="password" class="input w-full" name="s3_access_key_id" id="backupS3AccessKeyId" maxlength="128" autocomplete="off" placeholder="{{ $backupSecretSources[\App\Services\Backups\BackupSettings::S3_ACCESS_KEY_ID] === 'none' ? '' : 'Leave blank to keep the current key' }}">
                            @error('s3_access_key_id') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Secret access key <span class="badge badge-ghost badge-sm">{{ $backupSecretSourceLabels[$backupSecretSources[\App\Services\Backups\BackupSettings::S3_SECRET_ACCESS_KEY]] }}</span></span>
                            <input type="password" class="input w-full" name="s3_secret_access_key" id="backupS3SecretAccessKey" maxlength="256" autocomplete="off" placeholder="{{ $backupSecretSources[\App\Services\Backups\BackupSettings::S3_SECRET_ACCESS_KEY] === 'none' ? '' : 'Leave blank to keep the current secret' }}">
                            @error('s3_secret_access_key') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Custom endpoint (optional)</span>
                            <input type="url" class="input w-full" name="s3_endpoint" id="backupS3Endpoint" value="{{ old('s3_endpoint', $backupConfiguration->s3Endpoint) }}" maxlength="255" placeholder="https://nyc3.digitaloceanspaces.com">
                            <span class="block text-sm text-base-content/70">Leave blank for AWS.</span>
                            @error('s3_endpoint') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                        </label>

                        <label class="flex cursor-pointer items-start gap-3 self-center">
                            <input type="hidden" name="s3_path_style" value="0">
                            <input class="checkbox mt-0.5" type="checkbox" name="s3_path_style" id="backupS3PathStyle" value="1" @checked(old('s3_path_style', $backupConfiguration->s3PathStyle))>
                            <span class="text-sm">Use path-style URLs <span class="block text-base-content/70">Only if your storage provider requires it.</span></span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4 rounded-box border border-base-300 p-4">
                    <legend class="px-1 text-sm font-semibold">Archive encryption <span class="badge badge-ghost badge-sm">{{ $backupSecretSourceLabels[$backupSecretSources[\App\Services\Backups\BackupSettings::ARCHIVE_PASSWORD]] }}</span></legend>
                    <p class="text-sm text-base-content/70">Backups are encrypted with AES-256 using this password. Keep a copy somewhere other than this server: without it, backups cannot be opened. Changing it does not re-encrypt older backups. Required for S3.</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">New archive password</span>
                            <input type="password" class="input w-full" name="archive_password" id="backupArchivePassword" minlength="12" maxlength="256" autocomplete="new-password" placeholder="{{ $backupSecretSources[\App\Services\Backups\BackupSettings::ARCHIVE_PASSWORD] === 'none' ? '' : 'Leave blank to keep the current password' }}">
                            @error('archive_password') <span class="block text-sm text-error">{{ $message }}</span> @enderror
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Confirm archive password</span>
                            <input type="password" class="input w-full" name="archive_password_confirmation" id="backupArchivePasswordConfirmation" maxlength="256" autocomplete="new-password">
                        </label>
                    </div>
                </fieldset>

                <button class="btn btn-primary" type="submit">Save and check connection</button>
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

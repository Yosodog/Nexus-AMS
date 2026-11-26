<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BackupDestination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RunBackupNowRequest;
use App\Http\Requests\Admin\UpdateAuditRetentionSettingsRequest;
use App\Http\Requests\Admin\UpdateBackupConfigurationRequest;
use App\Http\Requests\Admin\UpdateBackupSettingsRequest;
use App\Http\Requests\Admin\UpdateUserInactivityAutoDisableRequest;
use App\Services\AuditLogger;
use App\Services\Backups\BackupConfiguration;
use App\Services\Backups\BackupConnectionChecker;
use App\Services\Backups\BackupRunService;
use App\Services\Backups\BackupRuntimeConfig;
use App\Services\Backups\BackupSettings;
use App\Services\Settings\SecurityRetentionSettings;
use App\Support\BackupSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SecurityRetentionSettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SecurityRetentionSettings $settings,
        private readonly BackupSettings $backupSettings,
        private readonly BackupRuntimeConfig $backupRuntimeConfig,
        private readonly BackupRunService $backupRuns,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user?->canAny(['view-diagnostic-info', 'edit-users']), 403);

        $viewData = [];

        if ($user->can('view-diagnostic-info')) {
            $backupConfiguration = $this->backupSettings->effective();

            $viewData = [
                'backupsEnabled' => $this->settings->isBackupsEnabled(),
                'backupConfiguration' => $backupConfiguration,
                'backupConfigurationSavedInAdmin' => $this->backupSettings->hasStoredConfiguration(),
                'backupSecretSources' => $this->backupSettings->secretSources(),
                'backupSchedulePreset' => BackupSchedule::presetFor($backupConfiguration->scheduleCron),
                'backupSchedulePresets' => BackupSchedule::PRESETS,
                'backupDestinations' => BackupDestination::cases(),
                'backupFolderName' => (string) config('backup.backup.name'),
                'backupHealth' => $this->backupRuns->health(),
                'backupVerificationEnabled' => (bool) config('backup.backup.verify_backup'),
                'backupFailureAlertsEnabled' => (bool) config('backup.notifications.failure_alerts_enabled'),
                'auditRetentionDays' => $this->settings->getAuditLogRetentionDays(),
            ];
        }

        if ($user->can('edit-users')) {
            $viewData['userInactivityAutoDisableEnabled'] = $this->settings->isUserInactivityAutoDisableEnabled();
            $viewData['userInactivityAutoDisableDays'] = $this->settings->getUserInactivityAutoDisableDays();
        }

        return view('admin.settings.security-retention', $viewData);
    }

    public function updateBackups(UpdateBackupSettingsRequest $request): RedirectResponse
    {
        $previous = $this->settings->isBackupsEnabled();
        $enabled = (bool) $request->validated('backups_enabled');

        $this->settings->setBackupsEnabled($enabled);

        $this->auditLogger->success(
            category: 'settings',
            action: 'backups_toggle',
            context: [
                'changes' => [
                    'backups_enabled' => [
                        'from' => $previous,
                        'to' => $enabled,
                    ],
                ],
            ],
            message: 'Backups setting updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => $enabled ? 'Backups enabled.' : 'Backups disabled.',
            'alert-type' => 'success',
        ]);
    }

    public function updateBackupConfiguration(
        UpdateBackupConfigurationRequest $request,
        BackupConnectionChecker $checker,
    ): RedirectResponse {
        $validated = $request->validated();
        $current = $this->backupSettings->effective();
        $destination = BackupDestination::from($validated['destination']);
        $newSecrets = array_filter([
            BackupSettings::S3_ACCESS_KEY_ID => trim((string) ($validated['s3_access_key_id'] ?? '')),
            BackupSettings::S3_SECRET_ACCESS_KEY => trim((string) ($validated['s3_secret_access_key'] ?? '')),
            BackupSettings::ARCHIVE_PASSWORD => (string) ($validated['archive_password'] ?? ''),
        ], fn (string $value): bool => $value !== '');

        $candidate = new BackupConfiguration(
            destination: $destination,
            scheduleCron: $validated['schedule_preset'] === 'custom'
                ? (string) $validated['schedule_cron']
                : BackupSchedule::PRESETS[$validated['schedule_preset']],
            s3Bucket: $destination->usesS3() ? $validated['s3_bucket'] : $current->s3Bucket,
            s3Region: $destination->usesS3() ? $validated['s3_region'] : $current->s3Region,
            s3Endpoint: $validated['s3_endpoint'] ?? null,
            s3PathStyle: (bool) $validated['s3_path_style'],
            s3Folder: $validated['s3_folder'] ?? null,
            s3AccessKeyId: $newSecrets[BackupSettings::S3_ACCESS_KEY_ID] ?? $current->s3AccessKeyId,
            s3SecretAccessKey: $newSecrets[BackupSettings::S3_SECRET_ACCESS_KEY] ?? $current->s3SecretAccessKey,
            archivePassword: $newSecrets[BackupSettings::ARCHIVE_PASSWORD] ?? $current->archivePassword,
        );

        $this->ensureRequiredSecrets($candidate);

        $results = $checker->check($candidate);
        $failures = BackupConnectionChecker::failures($results);

        if ($failures !== []) {
            return redirect()
                ->to(route('admin.settings.security-retention').'#backup-settings')
                ->withInput($request->safe()->except(['s3_access_key_id', 's3_secret_access_key', 'archive_password', 'archive_password_confirmation']))
                ->withErrors(['backup_check' => $failures])
                ->with('backup-check-results', $results);
        }

        $this->backupSettings->save($candidate, $newSecrets);
        $this->backupRuntimeConfig->apply();

        $this->auditLogger->success(
            category: 'settings',
            action: 'backup_configuration_updated',
            context: [
                'changes' => $this->backupConfigurationChanges($current, $candidate),
                'data' => [
                    'secrets_changed' => array_keys($newSecrets),
                    'checks_passed' => array_column($results, 'check'),
                ],
            ],
            message: 'Backup configuration updated.'
        );

        return redirect()
            ->to(route('admin.settings.security-retention').'#backup-settings')
            ->with([
                'alert-message' => 'Backup settings saved. Every connection check passed.',
                'alert-type' => 'success',
                'backup-check-results' => $results,
            ]);
    }

    public function runBackupNow(RunBackupNowRequest $request): RedirectResponse
    {
        $run = $this->backupRuns->requestManual($request->user());

        $this->auditLogger->success(
            category: 'settings',
            action: 'backup_run_requested',
            context: ['data' => ['backup_run_id' => $run->getKey()]],
            message: 'On-demand backup requested.'
        );

        return redirect()
            ->to(route('admin.settings.security-retention').'#backup-settings')
            ->with([
                'alert-message' => 'Backup queued. The scheduler starts it within a minute; refresh this page to follow it.',
                'alert-type' => 'success',
            ]);
    }

    public function updateAuditRetention(UpdateAuditRetentionSettingsRequest $request): RedirectResponse
    {
        $previous = $this->settings->getAuditLogRetentionDays();
        $updated = (int) $request->validated('audit_log_retention_days');

        $this->settings->setAuditLogRetentionDays($updated);

        $this->auditLogger->success(
            category: 'settings',
            action: 'audit_retention_updated',
            context: [
                'changes' => [
                    'audit_log_retention_days' => [
                        'from' => $previous,
                        'to' => $updated,
                    ],
                ],
            ],
            message: 'Audit log retention updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Audit log retention updated.',
            'alert-type' => 'success',
        ]);
    }

    public function updateUserInactivity(UpdateUserInactivityAutoDisableRequest $request): RedirectResponse
    {
        $previousEnabled = $this->settings->isUserInactivityAutoDisableEnabled();
        $previousDays = $this->settings->getUserInactivityAutoDisableDays();
        $validated = $request->validated();
        $enabled = (bool) $validated['user_inactivity_auto_disable_enabled'];
        $days = (int) $validated['user_inactivity_auto_disable_days'];

        DB::transaction(function () use ($enabled, $days): void {
            $this->settings->setUserInactivityAutoDisableEnabled($enabled);
            $this->settings->setUserInactivityAutoDisableDays($days);
        });

        $this->auditLogger->success(
            category: 'settings',
            action: 'user_inactivity_auto_disable_updated',
            context: [
                'changes' => [
                    'user_inactivity_auto_disable_enabled' => [
                        'from' => $previousEnabled,
                        'to' => $enabled,
                    ],
                    'user_inactivity_auto_disable_days' => [
                        'from' => $previousDays,
                        'to' => $days,
                    ],
                ],
            ],
            message: 'User inactivity auto-disable settings updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'User inactivity auto-disable settings updated.',
            'alert-type' => 'success',
        ]);
    }

    private function ensureRequiredSecrets(BackupConfiguration $candidate): void
    {
        if (! $candidate->destination->usesS3()) {
            return;
        }

        $messages = array_filter([
            's3_access_key_id' => blank($candidate->s3AccessKeyId) ? 'Enter the S3 access key ID.' : null,
            's3_secret_access_key' => blank($candidate->s3SecretAccessKey) ? 'Enter the S3 secret access key.' : null,
            'archive_password' => $candidate->hasArchivePassword() ? null : 'Set an archive password before sending backups off this server.',
        ]);

        if ($messages !== []) {
            throw ValidationException::withMessages($messages)->redirectTo(route('admin.settings.security-retention').'#backup-settings');
        }
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function backupConfigurationChanges(BackupConfiguration $from, BackupConfiguration $to): array
    {
        $fields = [
            'destination' => [$from->destination->value, $to->destination->value],
            'schedule_cron' => [$from->scheduleCron, $to->scheduleCron],
            's3_bucket' => [$from->s3Bucket, $to->s3Bucket],
            's3_region' => [$from->s3Region, $to->s3Region],
            's3_endpoint' => [$from->s3Endpoint, $to->s3Endpoint],
            's3_path_style' => [$from->s3PathStyle, $to->s3PathStyle],
            's3_folder' => [$from->s3Folder, $to->s3Folder],
        ];

        return collect($fields)
            ->reject(fn (array $pair): bool => $pair[0] === $pair[1])
            ->map(fn (array $pair): array => ['from' => $pair[0], 'to' => $pair[1]])
            ->all();
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DownloadDatabaseBackupRequest;
use App\Http\Requests\Admin\UpdateAuditRetentionSettingsRequest;
use App\Http\Requests\Admin\UpdateBackupSettingsRequest;
use App\Http\Requests\Admin\UpdateUserInactivityAutoDisableRequest;
use App\Services\AuditLogger;
use App\Services\DatabaseBackupDownloadService;
use App\Services\Settings\SecurityRetentionSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SecurityRetentionSettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DatabaseBackupDownloadService $databaseBackupDownloadService,
        private readonly SecurityRetentionSettings $settings,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user?->canAny(['view-diagnostic-info', 'edit-users']), 403);

        $viewData = [];

        if ($user->can('view-diagnostic-info')) {
            $viewData = [
                'backupsEnabled' => $this->settings->isBackupsEnabled(),
                'backupDisks' => config('backup.backup.destination.disks', []),
                'backupVerificationEnabled' => (bool) config('backup.backup.verify_backup'),
                'backupFailureAlertsEnabled' => (bool) config('backup.notifications.failure_alerts_enabled'),
                'backupArchivePasswordConfigured' => filled(config('backup.backup.password')),
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

    public function downloadDatabaseBackup(DownloadDatabaseBackupRequest $request): BinaryFileResponse|RedirectResponse
    {
        try {
            $archivePath = $this->databaseBackupDownloadService->create();
        } catch (Throwable $exception) {
            Log::error('On-demand database backup creation failed.', [
                'exception_class' => $exception::class,
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return redirect()->route('admin.settings.security-retention')->with([
                'alert-message' => 'The database backup could not be created. Check the application logs and backup tooling, then try again.',
                'alert-type' => 'error',
            ]);
        }

        $response = response()->download($archivePath, basename($archivePath), [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Type' => 'application/zip',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);

        $this->auditLogger->success(
            category: 'settings',
            action: 'database_backup_generated_for_download',
            context: [
                'archive_size_bytes' => filesize($archivePath) ?: 0,
                'database_driver' => DB::getDriverName(),
            ],
            message: 'Administrator generated a database backup for download.',
        );

        return $response;
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
}

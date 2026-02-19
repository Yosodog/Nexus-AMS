<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaviconRequest;
use App\Services\AuditLogger;
use App\Services\LoanService;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @throws AuthorizationException
     */
    public function index(): View
    {
        $this->authorize('view-diagnostic-info');

        $manualNationBatchId = SettingService::getLastManualNationSyncBatchId();
        $rollingNationBatchId = SettingService::getLastRollingNationSyncBatchId();
        $allianceBatchId = SettingService::getLastAllianceSyncBatchId();
        $warBatchId = SettingService::getLastWarSyncBatchId();

        $nationBatch = $manualNationBatchId ? Bus::findBatch($manualNationBatchId) : null;
        $rollingNationBatch = $rollingNationBatchId ? Bus::findBatch($rollingNationBatchId) : null;
        $allianceBatch = $allianceBatchId ? Bus::findBatch($allianceBatchId) : null;
        $warBatch = $warBatchId ? Bus::findBatch($warBatchId) : null;

        $appName = config('app.name');
        $homepageSettings = [
            'headline' => SettingService::getHomepageHeadline($appName),
            'tagline' => SettingService::getHomepageTagline($appName),
            'about' => SettingService::getHomepageAbout($appName),
            'highlights' => SettingService::getHomepageHighlights(),
        ];

        return view('admin.settings', [
            'nationBatch' => $nationBatch,
            'rollingNationBatch' => $rollingNationBatch,
            'rollingSchedule' => $this->buildRollingScheduleContext($rollingNationBatch),
            'allianceBatch' => $allianceBatch,
            'warBatch' => $warBatch,
            'discordVerificationRequired' => SettingService::isDiscordVerificationRequired(),
            'discordDepartureChannelId' => SettingService::getDiscordAllianceDepartureChannelId(),
            'discordDepartureEnabled' => SettingService::isDiscordAllianceDepartureEnabled(),
            'homepageSettings' => $homepageSettings,
            'autoWithdrawEnabled' => SettingService::isAutoWithdrawEnabled(),
            'loanPaymentsEnabled' => SettingService::isLoanPaymentsEnabled(),
            'loanPaymentsPausedAt' => SettingService::getLoanPaymentsPausedAt(),
            'grantApprovalsEnabled' => SettingService::isGrantApprovalsEnabled(),
            'auditRetentionDays' => SettingService::getAuditLogRetentionDays(),
        ]);
    }

    public function runSyncNation(): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        Artisan::call('sync:nations');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Nation sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }

    public function runSyncAlliance(): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        Artisan::call('sync:alliances');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Alliance sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }

    public function runSyncWar(): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        Artisan::call('sync:wars');

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'War sync command dispatched.',
            'alert-type' => 'success',
        ]);
    }

    public function cancelSync(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $request->validate([
            'batch_id' => 'required|string',
            'type' => 'required|in:nation,rolling_nation,alliance,war',
        ]);

        $batch = Bus::findBatch($request->input('batch_id'));

        if ($batch && ! $batch->finished() && ! $batch->cancelled()) {
            $batch->cancel();
        }

        $typeLabel = match ($request->input('type')) {
            'rolling_nation' => 'Rolling nation',
            default => ucfirst($request->input('type')),
        };

        $message = "{$typeLabel} sync cancelled.";

        return redirect()->route('admin.settings')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }

    public function updateDiscordRequirement(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $previous = SettingService::isDiscordVerificationRequired();
        $validated = $request->validate([
            'require_discord_verification' => ['required', 'boolean'],
        ]);

        $required = (bool) $validated['require_discord_verification'];

        SettingService::setDiscordVerificationRequired($required);

        $this->auditLogger->success(
            category: 'settings',
            action: 'discord_verification_requirement_updated',
            context: [
                'changes' => [
                    'require_discord_verification' => [
                        'from' => $previous,
                        'to' => $required,
                    ],
                ],
            ],
            message: 'Discord verification requirement updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => $required ? 'Discord verification is now required.' : 'Discord verification is now optional.',
            'alert-type' => 'success',
        ]);
    }

    public function updateDiscordDeparture(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $previousEnabled = SettingService::isDiscordAllianceDepartureEnabled();
        $previousChannel = SettingService::getDiscordAllianceDepartureChannelId();
        $validated = $request->validate([
            'discord_alliance_departure_enabled' => ['required', 'boolean'],
            'discord_alliance_departure_channel_id' => ['nullable', 'string', 'max:255'],
        ]);

        $enabled = (bool) $validated['discord_alliance_departure_enabled'];
        $channelId = $validated['discord_alliance_departure_channel_id'] ?? null;

        SettingService::setDiscordAllianceDepartureEnabled($enabled);
        SettingService::setDiscordAllianceDepartureChannelId($channelId);

        $this->auditLogger->success(
            category: 'settings',
            action: 'discord_departure_settings_updated',
            context: [
                'changes' => [
                    'discord_alliance_departure_enabled' => [
                        'from' => $previousEnabled,
                        'to' => $enabled,
                    ],
                    'discord_alliance_departure_channel_id' => [
                        'from' => $previousChannel,
                        'to' => $channelId,
                    ],
                ],
            ],
            message: 'Discord departure settings updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Discord alliance departure settings updated.',
            'alert-type' => 'success',
        ]);
    }

    public function updateHomepage(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $previous = [
            'home_headline' => SettingService::getHomepageHeadline(config('app.name')),
            'home_tagline' => SettingService::getHomepageTagline(config('app.name')),
            'home_about' => SettingService::getHomepageAbout(config('app.name')),
            'home_highlights' => SettingService::getHomepageHighlights(),
        ];
        $validated = $request->validate([
            'home_headline' => ['required', 'string', 'max:160'],
            'home_tagline' => ['required', 'string', 'max:240'],
            'home_about' => ['nullable', 'string', 'max:800'],
            'home_highlights' => ['array'],
            'home_highlights.*' => ['nullable', 'string', 'max:140'],
        ]);

        SettingService::setHomepageHeadline($validated['home_headline']);
        SettingService::setHomepageTagline($validated['home_tagline']);
        SettingService::setHomepageAbout($validated['home_about'] ?? '');

        $highlights = collect($validated['home_highlights'] ?? [])
            ->map(fn ($item) => (string) $item)
            ->all();

        SettingService::setHomepageHighlights($highlights);

        $this->auditLogger->success(
            category: 'settings',
            action: 'homepage_settings_updated',
            context: [
                'changes' => [
                    'home_headline' => ['from' => $previous['home_headline'], 'to' => $validated['home_headline']],
                    'home_tagline' => ['from' => $previous['home_tagline'], 'to' => $validated['home_tagline']],
                    'home_about' => ['from' => $previous['home_about'], 'to' => $validated['home_about'] ?? ''],
                    'home_highlights' => ['from' => $previous['home_highlights'], 'to' => $highlights],
                ],
            ],
            message: 'Homepage settings updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Homepage content updated.',
            'alert-type' => 'success',
        ]);
    }

    public function updateAutoWithdraw(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');

        $previous = SettingService::isAutoWithdrawEnabled();
        $validated = $request->validate([
            'auto_withdraw_enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['auto_withdraw_enabled'];

        SettingService::setAutoWithdrawEnabled($enabled);

        $this->auditLogger->success(
            category: 'settings',
            action: 'auto_withdraw_toggle',
            context: [
                'changes' => [
                    'auto_withdraw_enabled' => [
                        'from' => $previous,
                        'to' => $enabled,
                    ],
                ],
            ],
            message: 'Auto withdraw setting updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => $enabled ? 'Auto withdraw enabled.' : 'Auto withdraw disabled.',
            'alert-type' => 'success',
        ]);
    }

    public function updateLoanPayments(Request $request, LoanService $loanService): RedirectResponse
    {
        $this->authorize('manage-loans');

        $previous = SettingService::isLoanPaymentsEnabled();
        $validated = $request->validate([
            'loan_payments_enabled' => ['required', 'boolean'],
        ]);

        $wasEnabled = SettingService::isLoanPaymentsEnabled();
        $enabled = (bool) $validated['loan_payments_enabled'];

        if (! $enabled && $wasEnabled) {
            SettingService::setLoanPaymentsPausedAt(now());
        }

        if ($enabled && ! $wasEnabled) {
            $pausedAt = SettingService::getLoanPaymentsPausedAt();
            $resumedAt = now();
            $updatedCount = $pausedAt ? $loanService->shiftLoanDueDatesForPausedPeriod($pausedAt, $resumedAt) : 0;

            SettingService::setLoanPaymentsPausedAt(null);

            SettingService::setLoanPaymentsEnabled(true);

            $this->auditLogger->success(
                category: 'settings',
                action: 'loan_payments_resumed',
                context: [
                    'changes' => [
                        'loan_payments_enabled' => [
                            'from' => $wasEnabled,
                            'to' => true,
                        ],
                        'loan_payments_paused_at' => [
                            'from' => $pausedAt?->toIso8601String(),
                            'to' => null,
                        ],
                    ],
                    'data' => [
                        'adjusted_due_dates' => $updatedCount,
                    ],
                ],
                message: 'Loan payments resumed.'
            );

            return redirect()->route('admin.settings')->with([
                'alert-message' => $updatedCount > 0
                    ? "Loan payments resumed. Adjusted due dates for {$updatedCount} active loans."
                    : 'Loan payments resumed.',
                'alert-type' => 'success',
            ]);
        }

        SettingService::setLoanPaymentsEnabled($enabled);

        $this->auditLogger->success(
            category: 'settings',
            action: 'loan_payments_toggle',
            context: [
                'changes' => [
                    'loan_payments_enabled' => [
                        'from' => $previous,
                        'to' => $enabled,
                    ],
                ],
            ],
            message: 'Loan payment setting updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => $enabled ? 'Loan payments enabled.' : 'Loan payments paused.',
            'alert-type' => 'success',
        ]);
    }

    public function updateGrantApprovals(Request $request): RedirectResponse
    {
        $this->authorize('manage-grants');

        $previous = SettingService::isGrantApprovalsEnabled();
        $validated = $request->validate([
            'grant_approvals_enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['grant_approvals_enabled'];

        SettingService::setGrantApprovalsEnabled($enabled);

        $this->auditLogger->success(
            category: 'settings',
            action: 'grant_approvals_toggle',
            context: [
                'changes' => [
                    'grant_approvals_enabled' => [
                        'from' => $previous,
                        'to' => $enabled,
                    ],
                ],
            ],
            message: 'Grant approvals setting updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => $enabled ? 'Grant approvals enabled.' : 'Grant approvals paused.',
            'alert-type' => 'success',
        ]);
    }

    public function updateAuditRetention(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $previous = SettingService::getAuditLogRetentionDays();
        $validated = $request->validate([
            'audit_log_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $updated = (int) $validated['audit_log_retention_days'];
        SettingService::setAuditLogRetentionDays($updated);

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

    public function updateFavicon(StoreFaviconRequest $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $file = $request->file('favicon');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = $file->storeAs('branding', "favicon.{$extension}", 'public');

        $previousPath = SettingService::getFaviconPath();

        if ($previousPath && $previousPath !== $path && Storage::disk('public')->exists($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }

        SettingService::setFaviconPath($path);

        $this->auditLogger->success(
            category: 'settings',
            action: 'favicon_updated',
            context: [
                'changes' => [
                    'favicon_path' => [
                        'from' => $previousPath,
                        'to' => $path,
                    ],
                ],
            ],
            message: 'Favicon updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Favicon updated.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function buildRollingScheduleContext(?Batch $batch): array
    {
        $stepSeconds = $batch?->options['step_seconds'] ?? null;
        $scope = $batch?->options['scope'] ?? null;

        if (! $batch || ! $stepSeconds) {
            return [
                'scope' => $scope,
                'lastRunAt' => null,
                'nextRunAt' => null,
                'stepSeconds' => $stepSeconds,
            ];
        }

        $processed = $batch->processedJobs();
        $start = $batch->createdAt;

        $lastRunAt = $processed > 0
            ? $start->addSeconds($stepSeconds * max($processed - 1, 0))
            : null;

        $hasRemainingJobs = $processed < $batch->totalJobs && ! $batch->finished() && ! $batch->cancelled();

        $nextRunAt = $hasRemainingJobs
            ? $start->addSeconds($stepSeconds * $processed)
            : null;

        return [
            'scope' => $scope,
            'lastRunAt' => $lastRunAt,
            'nextRunAt' => $nextRunAt,
            'stepSeconds' => $stepSeconds,
        ];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaviconRequest;
use App\Http\Requests\Admin\UpdateGrowthCirclesRequest;
use App\Models\Application;
use App\Models\CityGrantRequest;
use App\Models\DepositRequest;
use App\Models\GrantApplication;
use App\Models\Loan;
use App\Models\RebuildingRequest;
use App\Models\WarAidRequest;
use App\Services\AuditLogger;
use App\Services\LoanService;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const DEFAULT_STALE_PENDING_HOURS = 24;

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
            'backupsEnabled' => SettingService::isBackupsEnabled(),
            'loanPaymentsEnabled' => SettingService::isLoanPaymentsEnabled(),
            'loanPaymentsPausedAt' => SettingService::getLoanPaymentsPausedAt(),
            'grantApprovalsEnabled' => SettingService::isGrantApprovalsEnabled(),
            'auditRetentionDays' => SettingService::getAuditLogRetentionDays(),
            'userInactivityAutoDisableEnabled' => SettingService::isUserInactivityAutoDisableEnabled(),
            'userInactivityAutoDisableDays' => SettingService::getUserInactivityAutoDisableDays(),
            'stalePendingDefaultHours' => self::DEFAULT_STALE_PENDING_HOURS,
            'pendingRecoveryItems' => $this->buildPendingRecoveryItems(),
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

    public function updateGrowthCircles(UpdateGrowthCirclesRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        SettingService::setGrowthCirclesEnabled((bool) $validated['growth_circles_enabled']);
        SettingService::setGrowthCircleTaxId((int) $validated['growth_circle_tax_id']);
        SettingService::setGrowthCircleFallbackTaxId((int) $validated['growth_circle_fallback_tax_id']);
        SettingService::setGrowthCircleSourceAccountId((int) $validated['growth_circle_source_account_id']);
        SettingService::setGrowthCircleFoodPerCity((int) $validated['growth_circle_food_per_city']);
        SettingService::setGrowthCircleUraniumPerCity((int) $validated['growth_circle_uranium_per_city']);
        SettingService::setGrowthCircleDiscordChannelId($validated['growth_circle_discord_channel_id'] ?? '');

        $this->auditLogger->success(
            category: 'settings',
            action: 'growth_circles_updated',
            context: ['data' => $validated],
            message: 'Growth Circles settings updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Growth Circles settings saved.',
            'alert-type' => 'success',
        ]);
    }

    public function updateBackups(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $previous = SettingService::isBackupsEnabled();
        $validated = $request->validate([
            'backups_enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['backups_enabled'];

        SettingService::setBackupsEnabled($enabled);

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

    public function updateUserInactivityAutoDisable(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');

        $previousEnabled = SettingService::isUserInactivityAutoDisableEnabled();
        $previousDays = SettingService::getUserInactivityAutoDisableDays();

        $validated = $request->validate([
            'user_inactivity_auto_disable_enabled' => ['required', 'boolean'],
            'user_inactivity_auto_disable_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $enabled = (bool) $validated['user_inactivity_auto_disable_enabled'];
        $days = (int) $validated['user_inactivity_auto_disable_days'];

        SettingService::setUserInactivityAutoDisableEnabled($enabled);
        SettingService::setUserInactivityAutoDisableDays($days);

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

    public function releaseStalePending(Request $request): RedirectResponse
    {
        $this->authorize('view-diagnostic-info');

        $definitions = $this->pendingRecoveryDefinitions();

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys($definitions))],
            'older_than_hours' => ['required', 'integer', 'min:1', 'max:8760'],
        ]);

        $type = (string) $validated['type'];
        $olderThanHours = (int) $validated['older_than_hours'];
        $definition = $definitions[$type];
        $cutoff = now()->subHours($olderThanHours);
        $releasedAt = now();

        $updated = DB::table($definition['table'])
            ->where('status', $definition['pending_status'])
            ->where('created_at', '<=', $cutoff)
            ->update(($definition['release_payload'])($releasedAt));

        $this->auditLogger->success(
            category: 'settings',
            action: 'stale_pending_requests_released',
            context: [
                'data' => [
                    'type' => $type,
                    'label' => $definition['label'],
                    'older_than_hours' => $olderThanHours,
                    'released_count' => $updated,
                    'cutoff' => $cutoff->toIso8601String(),
                ],
            ],
            message: 'Stale pending requests released.'
        );

        $message = $updated > 0
            ? "Released {$updated} stale {$definition['label']} entries older than {$olderThanHours} hours."
            : "No stale {$definition['label']} entries older than {$olderThanHours} hours were found.";

        return redirect()->route('admin.settings')->with([
            'alert-message' => $message,
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

    /**
     * @return array<string, array{
     *     label: string,
     *     table: string,
     *     model: class-string,
     *     pending_status: string,
     *     release_payload: \Closure(Carbon): array<string, mixed>
     * }>
     */
    private function pendingRecoveryDefinitions(): array
    {
        return [
            'war_aid' => [
                'label' => 'war aid requests',
                'table' => 'war_aid_requests',
                'model' => WarAidRequest::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'denied',
                    'pending_key' => null,
                    'denied_at' => $releasedAt,
                ],
            ],
            'applications' => [
                'label' => 'applications',
                'table' => 'applications',
                'model' => Application::class,
                'pending_status' => ApplicationStatus::Pending->value,
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => ApplicationStatus::Cancelled->value,
                    'pending_key' => null,
                    'cancelled_at' => $releasedAt,
                ],
            ],
            'loans' => [
                'label' => 'loan applications',
                'table' => 'loans',
                'model' => Loan::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'denied',
                    'pending_key' => null,
                ],
            ],
            'deposit_requests' => [
                'label' => 'deposit requests',
                'table' => 'deposit_requests',
                'model' => DepositRequest::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'expired',
                    'pending_key' => null,
                ],
            ],
            'grant_applications' => [
                'label' => 'grant applications',
                'table' => 'grant_applications',
                'model' => GrantApplication::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'denied',
                    'pending_key' => null,
                    'denied_at' => $releasedAt,
                ],
            ],
            'city_grant_requests' => [
                'label' => 'city grant requests',
                'table' => 'city_grant_requests',
                'model' => CityGrantRequest::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'denied',
                    'pending_key' => null,
                    'denied_at' => $releasedAt,
                ],
            ],
            'rebuilding_requests' => [
                'label' => 'rebuilding requests',
                'table' => 'rebuilding_requests',
                'model' => RebuildingRequest::class,
                'pending_status' => 'pending',
                'release_payload' => fn (Carbon $releasedAt): array => [
                    'status' => 'expired',
                    'pending_key' => null,
                    'expired_at' => $releasedAt,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{
     *     type: string,
     *     label: string,
     *     totalPending: int,
     *     stalePending: int,
     *     oldestCreatedAt: ?Carbon
     * }>
     */
    private function buildPendingRecoveryItems(): array
    {
        $cutoff = now()->subHours(self::DEFAULT_STALE_PENDING_HOURS);

        return collect($this->pendingRecoveryDefinitions())
            ->map(function (array $definition, string $type) use ($cutoff): array {
                /** @var class-string<Model> $model */
                $model = $definition['model'];

                $baseQuery = $model::query()->where('status', $definition['pending_status']);
                $oldestCreatedAt = (clone $baseQuery)->oldest('created_at')->value('created_at');

                return [
                    'type' => $type,
                    'label' => $definition['label'],
                    'totalPending' => (clone $baseQuery)->count(),
                    'stalePending' => (clone $baseQuery)->where('created_at', '<=', $cutoff)->count(),
                    'oldestCreatedAt' => $oldestCreatedAt ? Carbon::parse($oldestCreatedAt) : null,
                ];
            })
            ->values()
            ->all();
    }
}

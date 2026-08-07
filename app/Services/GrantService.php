<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\DataTransferObjects\GrantDecisionData;
use App\Enums\GrantDecisionReason;
use App\Events\AllianceExpenseOccurred;
use App\Models\Account;
use App\Models\AllianceFinanceEntry;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Nation;
use App\Notifications\GrantNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GrantService
{
    /**
     * @throws ValidationException
     */
    public static function applyToGrant(Grants $grant, Nation $nation, int $accountId): GrantApplication
    {
        return DB::transaction(function () use ($grant, $nation, $accountId) {
            $lockedGrant = Grants::query()
                ->lockForUpdate()
                ->findOrFail($grant->id);

            Nation::query()
                ->lockForUpdate()
                ->findOrFail($nation->id);

            self::validateEligibility($lockedGrant, $nation);

            return self::createApplication($lockedGrant, $nation->id, $accountId);
        }, attempts: 3);
    }

    /**
     * @throws ValidationException
     */
    public static function validateEligibility(Grants $grant, Nation $nation): void
    {
        if (! $grant->is_enabled) {
            throw ValidationException::withMessages([
                'This grant is currently disabled.',
            ]);
        }

        // One-time grants: check if they've already been approved
        if ($grant->is_one_time) {
            $alreadyApproved = GrantApplication::where('nation_id', $nation->id)
                ->where('grant_id', $grant->id)
                ->where('status', 'approved')
                ->exists();

            if ($alreadyApproved) {
                throw ValidationException::withMessages([
                    'You have already received this grant.',
                ]);
            }
        }

        // Check if there's a pending application
        $hasPending = GrantApplication::where('nation_id', $nation->id)
            ->where('grant_id', $grant->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'You already have a pending application for this grant.',
            ]);
        }

        // Run alliance + custom checks
        $validator = app(NationEligibilityValidator::class, ['nation' => $nation]);
        $validator->validateAllianceMembership();

        $requirements = $grant->validation_rules ?? [];
        app(GrantRequirementService::class)->assertEligible($requirements, $nation);
    }

    /**
     * @throws ValidationException
     */
    public static function createApplication(Grants $grant, int $nationId, int $accountId): GrantApplication
    {
        $submittedAt = now();

        try {
            $application = GrantApplication::create(array_merge(
                [
                    'grant_id' => $grant->id,
                    'program_name_snapshot' => $grant->name,
                    'program_version_snapshot' => max(1, (int) ($grant->version ?? 1)),
                    'nation_id' => $nationId,
                    'account_id' => $accountId,
                    'status' => 'pending',
                    'pending_key' => 1,
                    'submitted_at' => $submittedAt,
                ],
                self::resourceVector($grant),
            ));
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') === '23000') {
                throw ValidationException::withMessages([
                    'grant' => 'You already have a pending application for this grant.',
                ]);
            }

            throw $exception;
        }

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'grants',
            action: 'grant_application_submitted',
            outcome: 'success',
            severity: 'info',
            subject: $application,
            context: [
                'related' => [
                    ['type' => 'Grant', 'id' => (string) $grant->id, 'role' => 'grant'],
                    ['type' => 'Account', 'id' => (string) $accountId, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $nationId,
                ],
            ],
            message: 'Grant application submitted.'
        );

        return $application;
    }

    public static function approveGrant(
        GrantApplication $application,
        ?GrantDecisionData $decision = null,
    ): void {
        $decision ??= new GrantDecisionData(GrantDecisionReason::Approved);

        if ($decision->reason !== GrantDecisionReason::Approved) {
            throw ValidationException::withMessages([
                'reason_code' => 'An approval must use the approved decision reason.',
            ]);
        }

        $approvalSnapshot = GrantApplication::query()
            ->select(['id', 'grant_id', 'nation_id', 'account_id', 'status'])
            ->findOrFail($application->id);

        if ($approvalSnapshot->status !== 'pending') {
            Log::warning('Grant approval skipped because status is not pending.', [
                'application_id' => $approvalSnapshot->id,
                'status' => $approvalSnapshot->status,
            ]);

            return;
        }

        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $approvalSnapshot->nation_id,
            context: 'approve your own grant request'
        );

        if (! SettingService::isGrantApprovalsEnabled()) {
            Log::warning('Grant approval blocked by global approvals kill switch.', [
                'application_id' => $approvalSnapshot->id,
            ]);

            throw ValidationException::withMessages([
                'Grant approvals are currently paused.',
            ]);
        }

        $grantSnapshot = Grants::query()->findOrFail($approvalSnapshot->grant_id);

        if (! $grantSnapshot->is_enabled) {
            Log::warning('Grant approval blocked because grant is disabled.', [
                'application_id' => $approvalSnapshot->id,
                'grant_id' => $grantSnapshot->id,
            ]);

            throw ValidationException::withMessages([
                'This grant is currently disabled.',
            ]);
        }

        app(AuthoritativeNationMembershipService::class)->validate($approvalSnapshot->nation_id);

        $approvedApplication = null;
        $deniedApplication = null;

        DB::transaction(function () use ($approvalSnapshot, $decision, &$approvedApplication, &$deniedApplication) {
            $lockedApplication = GrantApplication::query()
                ->lockForUpdate()
                ->findOrFail($approvalSnapshot->id);

            if ($lockedApplication->status !== 'pending') {
                Log::warning('Grant approval blocked because status changed before locking.', [
                    'application_id' => $lockedApplication->id,
                    'status' => $lockedApplication->status,
                ]);

                throw ValidationException::withMessages([
                    'grant' => 'The grant application is no longer pending.',
                ]);
            }

            if (
                (int) $lockedApplication->nation_id !== (int) $approvalSnapshot->nation_id
                || (int) $lockedApplication->account_id !== (int) $approvalSnapshot->account_id
                || (int) $lockedApplication->grant_id !== (int) $approvalSnapshot->grant_id
            ) {
                Log::warning('Grant approval blocked because its recipient context changed.', [
                    'application_id' => $lockedApplication->id,
                    'expected_nation_id' => $approvalSnapshot->nation_id,
                    'actual_nation_id' => $lockedApplication->nation_id,
                    'expected_account_id' => $approvalSnapshot->account_id,
                    'actual_account_id' => $lockedApplication->account_id,
                    'expected_grant_id' => $approvalSnapshot->grant_id,
                    'actual_grant_id' => $lockedApplication->grant_id,
                ]);

                throw ValidationException::withMessages([
                    'grant' => 'The grant application changed while approval was in progress. Please review it and try again.',
                ]);
            }

            app(SelfApprovalGuard::class)->ensureNotSelf(
                requestNationId: $lockedApplication->nation_id,
                context: 'approve your own grant request'
            );

            if (! SettingService::isGrantApprovalsEnabled()) {
                Log::warning('Grant approval blocked by global approvals kill switch.', [
                    'application_id' => $lockedApplication->id,
                ]);

                throw ValidationException::withMessages([
                    'Grant approvals are currently paused.',
                ]);
            }

            $grant = Grants::query()
                ->lockForUpdate()
                ->findOrFail($lockedApplication->grant_id);

            if (! $grant->is_enabled) {
                Log::warning('Grant approval blocked because grant is disabled.', [
                    'application_id' => $lockedApplication->id,
                    'grant_id' => $grant->id,
                ]);

                throw ValidationException::withMessages([
                    'This grant is currently disabled.',
                ]);
            }

            $account = Account::query()
                ->lockForUpdate()
                ->findOrFail($lockedApplication->account_id);

            $nation = Nation::query()
                ->lockForUpdate()
                ->findOrFail($lockedApplication->nation_id);

            if ($account->nation_id !== $lockedApplication->nation_id) {
                Log::error('Grant approval denied due to account ownership mismatch.', [
                    'application_id' => $lockedApplication->id,
                    'account_id' => $account->id,
                    'account_nation_id' => $account->nation_id,
                    'request_nation_id' => $lockedApplication->nation_id,
                ]);

                $decidedAt = now();

                $lockedApplication->update([
                    'status' => 'denied',
                    'decision_reason_code' => GrantDecisionReason::AccountUnavailable,
                    'decision_explanation' => null,
                    'decision_internal_note' => 'The selected account was no longer owned by the applicant nation at approval time.',
                    'reviewed_by_user_id' => Auth::id(),
                    'denied_at' => $decidedAt,
                    'decided_at' => $decidedAt,
                    'pending_key' => null,
                ]);

                app(PendingRequestsService::class)->flushCache();

                $deniedApplication = $lockedApplication->fresh();

                return;
            }

            app(NationEligibilityValidator::class, ['nation' => $nation])->validateAllianceMembership();

            $adminId = Auth::id();
            $ipAddress = Request::ip();
            $correlationId = (string) Str::uuid();
            $decidedAt = now();

            $payout = self::payoutForDecision($lockedApplication, $grant);
            $programName = $lockedApplication->program_name_snapshot ?? $grant->name;
            $adjustment = $payout;
            $adjustment['note'] = "Grant '{$programName}' approved";

            AccountService::adjustAccountBalance($account, $adjustment, $adminId, $ipAddress, [
                'correlation_id' => $correlationId,
                'grant_application_id' => $lockedApplication->id,
                'grant_id' => $grant->id,
            ]);

            $decisionUpdate = [
                'status' => 'approved',
                'decision_reason_code' => $decision->reason,
                'decision_explanation' => $decision->memberExplanation,
                'decision_internal_note' => $decision->internalNote,
                'reviewed_by_user_id' => $adminId,
                'approved_at' => $decidedAt,
                'decided_at' => $decidedAt,
                'disbursed_at' => $decidedAt,
                'pending_key' => null,
            ];

            if (! $lockedApplication->hasProgramSnapshot()) {
                $decisionUpdate = array_merge($decisionUpdate, $payout);
            }

            $lockedApplication->update($decisionUpdate);

            self::dispatchGrantExpenseEvent($lockedApplication->fresh(), $grant, $account, $correlationId);

            self::logApprovalAnomalies($lockedApplication, $grant);

            app(PendingRequestsService::class)->flushCache();

            $approvedApplication = $lockedApplication->fresh();
        }, attempts: 3);

        if ($approvedApplication) {
            self::notifyDecision($approvedApplication, 'approved');

            app(AuditLogger::class)->recordAfterCommit(
                category: 'grants',
                action: 'grant_application_approved',
                outcome: 'success',
                severity: 'info',
                subject: $approvedApplication,
                context: [
                    'related' => [
                        ['type' => 'Grant', 'id' => (string) $approvedApplication->grant_id, 'role' => 'grant'],
                        ['type' => 'Account', 'id' => (string) $approvedApplication->account_id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $approvedApplication->nation_id,
                        'reason_code' => $approvedApplication->decision_reason_code?->value,
                    ],
                ],
                message: 'Grant application approved.'
            );
        }

        if ($deniedApplication) {
            self::notifyDecision($deniedApplication, 'denied');

            app(AuditLogger::class)->recordAfterCommit(
                category: 'grants',
                action: 'grant_application_denied',
                outcome: 'denied',
                severity: 'warning',
                subject: $deniedApplication,
                context: [
                    'related' => [
                        ['type' => 'Grant', 'id' => (string) $deniedApplication->grant_id, 'role' => 'grant'],
                        ['type' => 'Account', 'id' => (string) $deniedApplication->account_id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $deniedApplication->nation_id,
                        'reason_code' => $deniedApplication->decision_reason_code?->value,
                    ],
                ],
                message: 'Grant application denied.'
            );
        }
    }

    public static function denyGrant(
        GrantApplication $application,
        ?GrantDecisionData $decision = null,
    ): void {
        $deniedApplication = null;

        DB::transaction(function () use ($application, $decision, &$deniedApplication) {
            $lockedApplication = GrantApplication::query()
                ->with('nation')
                ->lockForUpdate()
                ->findOrFail($application->id);

            if ($lockedApplication->status !== 'pending') {
                throw ValidationException::withMessages([
                    'application' => 'Grant application is not pending.',
                ]);
            }

            if ($decision === null || ! $decision->reason->isDenial()) {
                throw ValidationException::withMessages([
                    'reason_code' => 'Select a member-safe decision reason before denying this request.',
                ]);
            }

            app(SelfApprovalGuard::class)->ensureNotSelf(
                requestNationId: $lockedApplication->nation_id,
                context: 'deny your own grant request'
            );

            $decidedAt = now();

            $lockedApplication->update([
                'status' => 'denied',
                'decision_reason_code' => $decision->reason,
                'decision_explanation' => $decision->memberExplanation,
                'decision_internal_note' => $decision->internalNote,
                'reviewed_by_user_id' => Auth::id(),
                'denied_at' => $decidedAt,
                'decided_at' => $decidedAt,
                'pending_key' => null,
            ]);

            $deniedApplication = $lockedApplication->fresh();
        }, attempts: 3);

        self::notifyDecision($deniedApplication, 'denied');

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'grants',
            action: 'grant_application_denied',
            outcome: 'denied',
            severity: 'warning',
            subject: $deniedApplication,
            context: [
                'related' => [
                    ['type' => 'Grant', 'id' => (string) $deniedApplication->grant_id, 'role' => 'grant'],
                    ['type' => 'Account', 'id' => (string) $deniedApplication->account_id, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $deniedApplication->nation_id,
                    'reason_code' => $deniedApplication->decision_reason_code?->value,
                ],
            ],
            message: 'Grant application denied.'
        );
    }

    /**
     * Count grant applications still pending approval.
     */
    public static function countPending(): int
    {
        return GrantApplication::where('status', 'pending')->count();
    }

    private static function dispatchGrantExpenseEvent(
        GrantApplication $application,
        Grants $grant,
        Account $account,
        ?string $correlationId = null
    ): void {
        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'grant',
            description: "Grant '".($application->program_name_snapshot ?? $grant->name)."' approved for Nation #{$application->nation_id}",
            date: $application->disbursed_at ?? now(),
            nationId: $application->nation_id,
            accountId: $account->id,
            source: $application,
            money: (float) ($application->money ?? 0.0),
            coal: (float) ($application->coal ?? 0.0),
            oil: (float) ($application->oil ?? 0.0),
            uranium: (float) ($application->uranium ?? 0.0),
            iron: (float) ($application->iron ?? 0.0),
            bauxite: (float) ($application->bauxite ?? 0.0),
            lead: (float) ($application->lead ?? 0.0),
            gasoline: (float) ($application->gasoline ?? 0.0),
            munitions: (float) ($application->munitions ?? 0.0),
            steel: (float) ($application->steel ?? 0.0),
            aluminum: (float) ($application->aluminum ?? 0.0),
            food: (float) ($application->food ?? 0.0),
            meta: [
                'grant_id' => $grant->id,
                'application_id' => $application->id,
                'correlation_id' => $correlationId,
            ]
        );

        event(new AllianceExpenseOccurred($financeData->toArray()));
    }

    private static function logApprovalAnomalies(GrantApplication $application, Grants $grant): void
    {
        $recentApprovals = GrantApplication::query()
            ->where('nation_id', $application->nation_id)
            ->where('status', 'approved')
            ->where('approved_at', '>=', now()->subMinutes(5))
            ->count();

        if ($recentApprovals > 1) {
            Log::warning('Multiple grant approvals detected in a short window.', [
                'nation_id' => $application->nation_id,
                'application_id' => $application->id,
                'recent_approvals' => $recentApprovals,
            ]);
        }

        $moneyThreshold = (int) config('grants.alert_thresholds.money', 0);

        if ($moneyThreshold > 0 && (float) ($application->money ?? 0) >= $moneyThreshold) {
            Log::warning('Grant approval exceeds configured money alert threshold.', [
                'application_id' => $application->id,
                'grant_id' => $grant->id,
                'money' => (float) ($application->money ?? 0),
                'threshold' => $moneyThreshold,
            ]);
        }

        $resourceThreshold = (int) config('grants.alert_thresholds.resource', 0);

        if ($resourceThreshold > 0) {
            $exceeded = collect(PWHelperService::resources(false))
                ->filter(fn ($resource) => (int) ($application->{$resource} ?? 0) >= $resourceThreshold)
                ->values();

            if ($exceeded->isNotEmpty()) {
                Log::warning('Grant approval exceeds configured resource alert threshold.', [
                    'application_id' => $application->id,
                    'grant_id' => $grant->id,
                    'resources' => $exceeded->all(),
                    'threshold' => $resourceThreshold,
                ]);
            }
        }
    }

    /**
     * @return array<string, int|float>
     */
    private static function resourceVector(Grants|GrantApplication $source): array
    {
        return collect(GrantApplication::PAYOUT_COLUMNS)
            ->mapWithKeys(fn (string $resource): array => [
                $resource => (int) ($source->{$resource} ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<string, int|float>
     */
    private static function payoutForDecision(GrantApplication $application, Grants $grant): array
    {
        return self::resourceVector($application->hasProgramSnapshot() ? $application : $grant);
    }

    private static function notifyDecision(GrantApplication $application, string $status): void
    {
        try {
            $application->loadMissing(['grant', 'nation']);
            $application->nation?->notify(new GrantNotification($application->nation_id, $application, $status));
        } catch (Throwable $exception) {
            Log::error('Grant decision notification failed after the decision was persisted.', [
                'application_id' => $application->id,
                'nation_id' => $application->nation_id,
                'status' => $status,
                'exception' => $exception::class,
            ]);
        }
    }
}

<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceExpenseOccurred;
use App\Models\Account;
use App\Models\AllianceFinanceEntry;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Nation;
use App\Notifications\GrantNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GrantService
{
    /**
     * @throws ValidationException
     */
    public static function applyToGrant(Grants $grant, Nation $nation, int $accountId): GrantApplication
    {
        return DB::transaction(function () use ($grant, $nation, $accountId) {
            Nation::query()
                ->lockForUpdate()
                ->findOrFail($nation->id);

            self::validateEligibility($grant, $nation);

            return self::createApplication($grant, $nation->id, $accountId);
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
        $validator = new NationEligibilityValidator($nation);
        $validator->validateAllianceMembership();

        $requirements = $grant->validation_rules ?? [];

        // Placeholder for future validation
        // $validator->validateGovernmentType($requirements["government_type"] ?? null);
        // $validator->validateColor($requirements["allowed_colors"] ?? []);
        // $validator->validateRequiredProjects($requirements["required_projects"] ?? []);
        // $validator->validateInfrastructure($requirements["infra_per_city"] ?? 0);
    }

    public static function createApplication(Grants $grant, int $nationId, int $accountId): GrantApplication
    {
        $application = GrantApplication::create([
            'grant_id' => $grant->id,
            'nation_id' => $nationId,
            'account_id' => $accountId,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        app(PendingRequestsService::class)->flushCache();

        return $application;
    }

    public static function approveGrant(GrantApplication $application): void
    {
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $application->nation_id,
            context: 'approve your own grant request'
        );

        DB::transaction(function () use ($application) {
            $lockedApplication = GrantApplication::query()
                ->lockForUpdate()
                ->findOrFail($application->id);

            if ($lockedApplication->status !== 'pending') {
                Log::warning('Grant approval skipped because status is not pending.', [
                    'application_id' => $lockedApplication->id,
                    'status' => $lockedApplication->status,
                ]);

                return;
            }

            if (! SettingService::isGrantApprovalsEnabled()) {
                Log::warning('Grant approval blocked by global approvals kill switch.', [
                    'application_id' => $lockedApplication->id,
                ]);

                throw ValidationException::withMessages([
                    'Grant approvals are currently paused.',
                ]);
            }

            $grant = $lockedApplication->grant;

            if (! $grant->is_enabled) {
                Log::warning('Grant approval blocked because grant is disabled.', [
                    'application_id' => $lockedApplication->id,
                    'grant_id' => $grant->id,
                ]);

                throw ValidationException::withMessages([
                    'This grant is currently disabled.',
                ]);
            }

            $account = Account::findOrFail($lockedApplication->account_id);

            if ($account->nation_id !== $lockedApplication->nation_id) {
                Log::error('Grant approval denied due to account ownership mismatch.', [
                    'application_id' => $lockedApplication->id,
                    'account_id' => $account->id,
                    'account_nation_id' => $account->nation_id,
                    'request_nation_id' => $lockedApplication->nation_id,
                ]);

                $lockedApplication->update([
                    'status' => 'denied',
                    'denied_at' => now(),
                    'pending_key' => null,
                ]);

                $lockedApplication->nation->notify(
                    new GrantNotification($lockedApplication->nation_id, $lockedApplication->fresh(), 'denied')
                );

                app(PendingRequestsService::class)->flushCache();

                return;
            }

            $adminId = Auth::id();
            $ipAddress = Request::ip();
            $correlationId = (string) Str::uuid();

            $resources = PWHelperService::resources();
            $adjustment = array_combine($resources, array_map(fn ($r) => $grant->$r, $resources));
            $adjustment['note'] = "Grant '{$grant->name}' approved";

            AccountService::adjustAccountBalance($account, $adjustment, $adminId, $ipAddress, [
                'correlation_id' => $correlationId,
                'grant_application_id' => $lockedApplication->id,
                'grant_id' => $grant->id,
            ]);

            $lockedApplication->update(
                array_merge(
                    [
                        'status' => 'approved',
                        'approved_at' => now(),
                        'pending_key' => null,
                    ],
                    array_combine($resources, array_map(fn ($r) => $grant->$r, $resources))
                )
            );

            $nation = $lockedApplication->nation;

            $nation->notify(new GrantNotification($lockedApplication->nation_id, $lockedApplication->fresh(), 'approved'));

            self::dispatchGrantExpenseEvent($lockedApplication->fresh(), $grant, $account, $correlationId);

            self::logApprovalAnomalies($lockedApplication, $grant);

            app(PendingRequestsService::class)->flushCache();
        }, attempts: 3);
    }

    public static function denyGrant(GrantApplication $application): void
    {
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $application->nation_id,
            context: 'deny your own grant request'
        );

        $application->update([
            'status' => 'denied',
            'denied_at' => now(),
            'pending_key' => null,
        ]);

        $application->nation->notify(new GrantNotification($application->nation_id, $application, 'denied'));

        app(PendingRequestsService::class)->flushCache();
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
            description: "Grant '{$grant->name}' approved for Nation #{$application->nation_id}",
            date: now(),
            nationId: $application->nation_id,
            accountId: $account->id,
            source: $application,
            money: (float) ($grant->money ?? 0.0),
            coal: (float) ($grant->coal ?? 0.0),
            oil: (float) ($grant->oil ?? 0.0),
            uranium: (float) ($grant->uranium ?? 0.0),
            iron: (float) ($grant->iron ?? 0.0),
            bauxite: (float) ($grant->bauxite ?? 0.0),
            lead: (float) ($grant->lead ?? 0.0),
            gasoline: (float) ($grant->gasoline ?? 0.0),
            munitions: (float) ($grant->munitions ?? 0.0),
            steel: (float) ($grant->steel ?? 0.0),
            aluminum: (float) ($grant->aluminum ?? 0.0),
            food: (float) ($grant->food ?? 0.0),
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

        if ($moneyThreshold > 0 && (float) ($grant->money ?? 0) >= $moneyThreshold) {
            Log::warning('Grant approval exceeds configured money alert threshold.', [
                'application_id' => $application->id,
                'grant_id' => $grant->id,
                'money' => (float) ($grant->money ?? 0),
                'threshold' => $moneyThreshold,
            ]);
        }

        $resourceThreshold = (int) config('grants.alert_thresholds.resource', 0);

        if ($resourceThreshold > 0) {
            $exceeded = collect(PWHelperService::resources(false))
                ->filter(fn ($resource) => (int) ($grant->{$resource} ?? 0) >= $resourceThreshold)
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
}

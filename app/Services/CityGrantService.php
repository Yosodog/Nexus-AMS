<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceExpenseOccurred;
use App\Models\Account;
use App\Models\AllianceFinanceEntry;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\Nation;
use App\Notifications\CityGrantNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CityGrantService
{
    public static function findGrantWithCityNum(int $cityNum): CityGrant
    {
        return CityGrant::where('city_number', $cityNum)
            ->where('enabled', true)
            ->firstOrFail();
    }

    public static function createRequest(CityGrant $grant, Nation $nation, int $accountId): CityGrantRequest
    {
        return DB::transaction(function () use ($grant, $nation, $accountId) {
            Nation::query()
                ->lockForUpdate()
                ->findOrFail($nation->id);

            self::validateEligibility($grant, $nation);

            $cityCostService = app(CityCostService::class);
            $grantAmount = $cityCostService->calculateGrantAmount($grant);

            if ($grantAmount === null) {
                throw ValidationException::withMessages([
                    'Unable to calculate the city grant amount right now. Please try again later.',
                ]);
            }

            $request = CityGrantRequest::create([
                'city_number' => $grant->city_number,
                'grant_amount' => (int) round($grantAmount),
                'nation_id' => $nation->id,
                'account_id' => $accountId,
                'status' => 'pending',
                'pending_key' => 1,
            ]);

            app(PendingRequestsService::class)->flushCache();

            app(AuditLogger::class)->recordAfterCommit(
                category: 'grants',
                action: 'city_grant_request_submitted',
                outcome: 'success',
                severity: 'info',
                subject: $request,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $accountId, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $nation->id,
                        'city_number' => $request->city_number,
                        'grant_amount' => $request->grant_amount,
                    ],
                ],
                message: 'City grant request submitted.'
            );

            return $request;
        }, attempts: 3);
    }

    /**
     * @throws ValidationException
     */
    public static function validateEligibility(CityGrant $grant, Nation $nation): void
    {
        if (! $grant->enabled) {
            throw ValidationException::withMessages([
                'This city grant is currently disabled.',
            ]);
        }

        // Make sure they don't have a pending city grant
        $hasPending = CityGrantRequest::where('nation_id', $nation->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages(['You have a pending city grant.']);
        }

        // Check to see if they've gotten this city tier before
        $alreadyApprovedForCity = CityGrantRequest::where('nation_id', $nation->id)
            ->where('city_number', $grant->city_number)
            ->where('status', 'approved')
            ->exists();

        if ($alreadyApprovedForCity) {
            throw ValidationException::withMessages(["You've already gotten that city grant"]);
        }

        $validator = new NationEligibilityValidator($nation);

        $validator->validateAllianceMembership();
        // $validator->validateGovernmentType($requirements["government_type"]);
        // $validator->validateColor($requirements["allowed_colors"]);
        // $validator->validateRequiredProjects($requirements["projects"]);
        // $validator->validateInfrastructure($requirements["inf_per_city"]);
        // TODO implement these checks later
    }

    /**
     * Approve a city grant request and allocate funds.
     */
    public static function approveGrant(CityGrantRequest $request): void
    {
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $request->nation_id,
            context: 'approve your own city grant request'
        );

        $approvedRequest = null;
        $deniedRequest = null;

        DB::transaction(function () use ($request, &$approvedRequest, &$deniedRequest) {
            $lockedRequest = CityGrantRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            if ($lockedRequest->status !== 'pending') {
                Log::warning('City grant approval skipped because status is not pending.', [
                    'request_id' => $lockedRequest->id,
                    'status' => $lockedRequest->status,
                ]);

                return;
            }

            if (! SettingService::isGrantApprovalsEnabled()) {
                Log::warning('City grant approval blocked by global approvals kill switch.', [
                    'request_id' => $lockedRequest->id,
                ]);

                throw ValidationException::withMessages([
                    'Grant approvals are currently paused.',
                ]);
            }

            $grant = CityGrant::where('city_number', $lockedRequest->city_number)->first();

            if (! $grant || ! $grant->enabled) {
                Log::warning('City grant approval blocked because grant is disabled or missing.', [
                    'request_id' => $lockedRequest->id,
                    'city_number' => $lockedRequest->city_number,
                ]);

                throw ValidationException::withMessages([
                    'This city grant is currently disabled.',
                ]);
            }

            // Fetch the recipient account
            $account = Account::findOrFail($lockedRequest->account_id);

            if ($account->nation_id !== $lockedRequest->nation_id) {
                Log::error('City grant approval denied due to account ownership mismatch.', [
                    'request_id' => $lockedRequest->id,
                    'account_id' => $account->id,
                    'account_nation_id' => $account->nation_id,
                    'request_nation_id' => $lockedRequest->nation_id,
                ]);

                $lockedRequest->update([
                    'status' => 'denied',
                    'denied_at' => now(),
                    'pending_key' => null,
                ]);

                $lockedRequest->nation->notify(
                    new CityGrantNotification($lockedRequest->nation_id, $lockedRequest->fresh(), 'denied')
                );

                app(PendingRequestsService::class)->flushCache();

                $deniedRequest = $lockedRequest->fresh();

                return;
            }

            $adminId = Auth::id();
            $ipAddress = Request::ip();
            $correlationId = (string) Str::uuid();

            // Adjust account balance
            $adjustment = [
                'money' => $lockedRequest->grant_amount,
                'note' => "City Grant Approved for City #{$lockedRequest->city_number}",
            ];

            AccountService::adjustAccountBalance($account, $adjustment, $adminId, $ipAddress, [
                'correlation_id' => $correlationId,
                'city_grant_request_id' => $lockedRequest->id,
                'city_number' => $lockedRequest->city_number,
            ]);

            // Update grant request status
            $lockedRequest->update([
                'status' => 'approved',
                'approved_at' => now(),
                'pending_key' => null,
            ]);

            $lockedRequest->nation->notify(new CityGrantNotification($lockedRequest->nation_id, $lockedRequest, 'approved'));

            self::dispatchCityGrantExpenseEvent($lockedRequest, $account, $correlationId);

            self::logApprovalAnomalies($lockedRequest);

            app(PendingRequestsService::class)->flushCache();

            $approvedRequest = $lockedRequest->fresh();
        }, attempts: 3);

        if ($approvedRequest) {
            app(AuditLogger::class)->recordAfterCommit(
                category: 'grants',
                action: 'city_grant_approved',
                outcome: 'success',
                severity: 'info',
                subject: $approvedRequest,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $approvedRequest->account_id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $approvedRequest->nation_id,
                        'city_number' => $approvedRequest->city_number,
                        'grant_amount' => $approvedRequest->grant_amount,
                    ],
                ],
                message: 'City grant approved.'
            );
        }

        if ($deniedRequest) {
            app(AuditLogger::class)->recordAfterCommit(
                category: 'grants',
                action: 'city_grant_denied',
                outcome: 'denied',
                severity: 'warning',
                subject: $deniedRequest,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $deniedRequest->account_id, 'role' => 'account'],
                    ],
                    'data' => [
                        'nation_id' => $deniedRequest->nation_id,
                        'reason' => 'account_mismatch',
                    ],
                ],
                message: 'City grant denied.'
            );
        }
    }

    /**
     * Deny a city grant request.
     */
    public static function denyGrant(CityGrantRequest $request): void
    {
        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $request->nation_id,
            context: 'deny your own city grant request'
        );

        $request->update([
            'status' => 'denied',
            'denied_at' => now(),
            'pending_key' => null,
        ]);

        $request->nation->notify(new CityGrantNotification($request->nation_id, $request, 'denied'));

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'grants',
            action: 'city_grant_denied',
            outcome: 'denied',
            severity: 'warning',
            subject: $request,
            context: [
                'related' => [
                    ['type' => 'Account', 'id' => (string) $request->account_id, 'role' => 'account'],
                ],
                'data' => [
                    'nation_id' => $request->nation_id,
                    'city_number' => $request->city_number,
                ],
            ],
            message: 'City grant denied.'
        );
    }

    /**
     * Count city grant requests awaiting review.
     */
    public static function countPending(): int
    {
        return CityGrantRequest::where('status', 'pending')->count();
    }

    private static function dispatchCityGrantExpenseEvent(
        CityGrantRequest $request,
        Account $account,
        ?string $correlationId = null
    ): void {
        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'city_grant',
            description: "City grant approved for Nation #{$request->nation_id}",
            date: now(),
            nationId: $request->nation_id,
            accountId: $account->id,
            source: $request,
            money: (float) $request->grant_amount,
            meta: [
                'city_number' => $request->city_number,
                'request_id' => $request->id,
                'correlation_id' => $correlationId,
            ]
        );

        event(new AllianceExpenseOccurred($financeData->toArray()));
    }

    private static function logApprovalAnomalies(CityGrantRequest $request): void
    {
        $recentApprovals = CityGrantRequest::query()
            ->where('nation_id', $request->nation_id)
            ->where('status', 'approved')
            ->where('approved_at', '>=', now()->subMinutes(5))
            ->count();

        if ($recentApprovals > 1) {
            Log::warning('Multiple city grant approvals detected in a short window.', [
                'nation_id' => $request->nation_id,
                'request_id' => $request->id,
                'recent_approvals' => $recentApprovals,
            ]);
        }

        $threshold = (int) config('grants.alert_thresholds.city_grant_amount', 0);

        if ($threshold > 0 && $request->grant_amount >= $threshold) {
            Log::warning('City grant approval exceeds configured alert threshold.', [
                'request_id' => $request->id,
                'grant_amount' => $request->grant_amount,
                'threshold' => $threshold,
            ]);
        }
    }
}

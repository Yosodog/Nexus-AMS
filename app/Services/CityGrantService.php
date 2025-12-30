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
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

class CityGrantService
{
    public static function findGrantWithCityNum(int $cityNum): CityGrant
    {
        return CityGrant::where('city_number', $cityNum)
            ->firstOrFail();
    }

    public static function createRequest(CityGrant $grant, int $nationId, int $accountId): CityGrantRequest
    {
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
            'nation_id' => $nationId,
            'account_id' => $accountId,
            'status' => 'pending',
        ]);

        app(PendingRequestsService::class)->flushCache();

        return $request;
    }

    /**
     * @throws ValidationException
     */
    public static function validateEligibility(CityGrant $grant, Nation $nation): void
    {
        // Make sure they don't have a pending city grant
        $pending = CityGrantRequest::where('nation_id', Auth::user()->nation_id)
            ->where('status', 'pending')
            ->get();

        if ($pending->count() > 0) {
            throw ValidationException::withMessages(['You have a pending city grant.']);
        }

        // Check to see if they've gotten this grant before
        $gotten = CityGrantRequest::where('nation_id', Auth::user()->nation_id)
            ->where('status', 'approved')
            ->get();

        if ($gotten->count() > 0) {
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

        // Fetch the recipient account
        $account = Account::findOrFail($request->account_id);
        $adminId = Auth::id();
        $ipAddress = Request::ip();

        // Adjust account balance
        $adjustment = [
            'money' => $request->grant_amount,
            'note' => "City Grant Approved for City #{$request->city_number}",
        ];

        AccountService::adjustAccountBalance($account, $adjustment, $adminId, $ipAddress);

        // Update grant request status
        $request->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $request->nation->notify(new CityGrantNotification($request->nation_id, $request, 'approved'));

        self::dispatchCityGrantExpenseEvent($request, $account);

        app(PendingRequestsService::class)->flushCache();
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
        ]);

        $request->nation->notify(new CityGrantNotification($request->nation_id, $request, 'denied'));

        app(PendingRequestsService::class)->flushCache();
    }

    /**
     * Count city grant requests awaiting review.
     */
    public static function countPending(): int
    {
        return CityGrantRequest::where('status', 'pending')->count();
    }

    private static function dispatchCityGrantExpenseEvent(CityGrantRequest $request, Account $account): void
    {
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
            ]
        );

        event(new AllianceExpenseOccurred($financeData->toArray()));
    }
}

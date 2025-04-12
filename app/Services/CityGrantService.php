<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\Nation;
use App\Notifications\CityGrantNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

class CityGrantService
{

    /**
     * @param int $cityNum
     *
     * @return CityGrant
     */
    public static function findGrantWithCityNum(int $cityNum): CityGrant
    {
        return CityGrant::where("city_number", $cityNum)
            ->firstOrFail();
    }

    /**
     * @param CityGrant $grant
     * @param int $nationId
     * @param int $accountId
     *
     * @return CityGrantRequest
     */
    public static function createRequest(CityGrant $grant, int $nationId, int $accountId): CityGrantRequest
    {
        return CityGrantRequest::create([
            "city_number" => $grant->city_number,
            "grant_amount" => $grant->grant_amount,
            "nation_id" => $nationId,
            "account_id" => $accountId,
            "status" => "pending",
        ]);
    }

    /**
     * @param CityGrant $grant
     * @param Nation $nation
     *
     * @return void
     * @throws ValidationException
     */
    public static function validateEligibility(CityGrant $grant, Nation $nation)
    {
        $requirements = $grant->requirements ?? [];

        // Make sure they don't have a pending city grant
        $pending = CityGrantRequest::where("nation_id", Auth::user()->nation_id)
            ->where("status", "pending")
            ->get();

        if ($pending->count() > 0) {
            throw ValidationException::withMessages(['You have a pending city grant.']);
        }

        // Check to see if they've gotten this grant before
        $gotten = CityGrantRequest::where("nation_id", Auth::user()->nation_id)
            ->where("status", "approved")
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
    }

    /**
     * Deny a city grant request.
     */
    public static function denyGrant(CityGrantRequest $request): void
    {
        $request->update([
            'status' => 'denied',
            'denied_at' => now(),
        ]);

        $request->nation->notify(new CityGrantNotification($request->nation_id, $request, 'denied'));
    }

}

<?php

namespace App\Services;

use App\Models\Accounts;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\Nations;
use App\Notifications\CityGrantNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class CityGrantService
{

    /**
     * @param  int  $cityNum
     *
     * @return \App\Models\CityGrant
     */
    public static function findGrantWithCityNum(int $cityNum): CityGrant
    {
        return CityGrant::where("city_number", $cityNum)
            ->firstOrFail();
    }

    /**
     * @param  \App\Models\CityGrant  $grant
     * @param  int  $nationId
     * @param  int  $accountId
     *
     * @return \App\Models\CityGrantRequest
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
     * @param  \App\Models\CityGrant  $grant
     * @param  \App\Models\Nations  $nation
     *
     * @return void
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validateEligibility(CityGrant $grant, Nations $nation)
    {
        $requirements = $grant->requirements ?? [];

        $validator = new NationEligibilityValidator($nation);

        $validator->validateAllianceMembership();
        $validator->validateGovernmentType($requirements["government_type"]);
        $validator->validateColor($requirements["allowed_colors"]);
        $validator->validateRequiredProjects($requirements["projects"]);
        $validator->validateInfrastructure($requirements["inf_per_city"]);
    }

    /**
     * Approve a city grant request and allocate funds.
     */
    public static function approveGrant(CityGrantRequest $request): void
    {
        // Fetch the recipient account
        $account = Accounts::findOrFail($request->account_id);
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

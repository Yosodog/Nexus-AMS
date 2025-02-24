<?php

namespace App\Services;

use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\Nations;

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

}

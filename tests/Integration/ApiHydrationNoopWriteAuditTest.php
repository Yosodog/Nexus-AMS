<?php

namespace Tests\Integration;

use App\Models\Alliance;
use App\Models\City;
use App\Models\Nation;
use App\Models\War;
use App\Models\WarAttack;
use Tests\Concerns\CapturesApiHydrationWrites;

/**
 * MySQL hands back 0/1 for booleans and strings for decimals, which SQLite does not, so the
 * no-op guarantee for every model hydrated from the API is proved against the real driver.
 */
class ApiHydrationNoopWriteAuditTest extends MySqlIntegrationTestCase
{
    use CapturesApiHydrationWrites;

    public function test_reapplying_unchanged_api_payloads_issues_no_updates_on_mysql(): void
    {
        Alliance::updateFromAPI($this->allianceFromApiJson($this->allianceApiJson(777)), withNations: false);
        Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9401, ['alliance_id' => null])));
        Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9402, ['alliance_id' => null, 'nation_name' => 'Other'])));
        City::updateFromAPI($this->cityFromApiJson($this->cityApiJson(5401, 9401)));
        War::updateFromAPI($this->warApiPayload(4401, 9401, 9402));
        WarAttack::storeFromEvent($this->warAttackApiPayload(3401, 4401, 9401, 9402));

        $updates = $this->captureUpdates(function (): void {
            Alliance::updateFromAPI($this->allianceFromApiJson($this->allianceApiJson(777)), withNations: false);
            Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9401, ['alliance_id' => null])));
            City::updateFromAPI($this->cityFromApiJson($this->cityApiJson(5401, 9401)));
            War::updateFromAPI($this->warApiPayload(4401, 9401, 9402));
            WarAttack::storeFromEvent($this->warAttackApiPayload(3401, 4401, 9401, 9402));
        });

        $this->assertSame([], $updates, json_encode($updates));
    }
}

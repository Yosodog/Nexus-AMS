<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\Nation;
use App\Services\CityGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\FeatureTestCase;

class CityGrantServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forever('alliances:membership:ids', [777]);
    }

    public function test_validate_eligibility_rejects_disabled_city_grants(): void
    {
        $grant = CityGrant::query()->create([
            'description' => 'Disabled',
            'enabled' => false,
            'grant_amount' => 100,
            'city_number' => 6,
            'requirements' => [],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This city grant is currently disabled.');

        CityGrantService::validateEligibility($grant, $this->createNation());
    }

    public function test_validate_eligibility_rejects_city_numbers_already_approved(): void
    {
        $nation = $this->createNation();
        $account = $this->createAccount($nation);
        $grant = CityGrant::query()->create([
            'description' => 'Enabled',
            'enabled' => true,
            'grant_amount' => 100,
            'city_number' => 6,
            'requirements' => [],
        ]);

        CityGrantRequest::query()->create([
            'city_number' => 6,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'approved',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("You've already gotten that city grant");

        CityGrantService::validateEligibility($grant, $nation);
    }

    public function test_log_approval_anomalies_warns_when_amount_threshold_is_exceeded(): void
    {
        Log::spy();

        config()->set('grants.alert_thresholds.city_grant_amount', 200000);

        $nation = $this->createNation();
        $account = $this->createAccount($nation);
        $request = CityGrantRequest::query()->create([
            'city_number' => 7,
            'grant_amount' => 320000,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $reflection = new ReflectionMethod(CityGrantService::class, 'logApprovalAnomalies');
        $reflection->setAccessible(true);
        $reflection->invoke(null, $request);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'City grant approval exceeds configured alert threshold.'
            && $context['request_id'] === $request->id);
    }

    private function createNation(): Nation
    {
        return Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'num_cities' => 5,
        ]);
    }

    private function createAccount(Nation $nation): Account
    {
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        return $account;
    }
}

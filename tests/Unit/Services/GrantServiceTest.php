<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Nation;
use App\Services\GrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\FeatureTestCase;

class GrantServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forever('alliances:membership:ids', [777]);
    }

    public function test_validate_eligibility_rejects_disabled_grants(): void
    {
        $grant = $this->createGrant(['is_enabled' => false]);
        $nation = $this->createNation();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This grant is currently disabled.');

        GrantService::validateEligibility($grant, $nation);
    }

    public function test_validate_eligibility_rejects_one_time_grants_that_were_already_approved(): void
    {
        $grant = $this->createGrant(['is_one_time' => true]);
        $nation = $this->createNation();
        $account = $this->createAccount($nation);

        GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'approved',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You have already received this grant.');

        GrantService::validateEligibility($grant, $nation);
    }

    public function test_log_approval_anomalies_warns_when_money_threshold_is_exceeded(): void
    {
        Log::spy();

        config()->set('grants.alert_thresholds.money', 1000);
        $grant = $this->createGrant(['money' => 2500]);
        $nation = $this->createNation();
        $account = $this->createAccount($nation);
        $application = GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->invokePrivate('logApprovalAnomalies', [$application, $grant]);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Grant approval exceeds configured money alert threshold.'
            && $context['grant_id'] === $grant->id);
    }

    private function createNation(): Nation
    {
        return Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGrant(array $overrides = []): Grants
    {
        $grant = new Grants;
        $grant->name = $overrides['name'] ?? 'Grant '.uniqid();
        $grant->slug = array_key_exists('slug', $overrides) ? $overrides['slug'] : 'grant-'.uniqid();
        $grant->description = 'Grant';
        $grant->money = $overrides['money'] ?? 0;
        $grant->coal = 0;
        $grant->oil = 0;
        $grant->uranium = 0;
        $grant->iron = 0;
        $grant->bauxite = 0;
        $grant->lead = 0;
        $grant->gasoline = 0;
        $grant->munitions = 0;
        $grant->steel = 0;
        $grant->aluminum = 0;
        $grant->food = 0;
        $grant->validation_rules = [];
        $grant->is_enabled = $overrides['is_enabled'] ?? true;
        $grant->is_one_time = $overrides['is_one_time'] ?? false;
        $grant->save();

        return $grant;
    }

    private function invokePrivate(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(GrantService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $arguments);
    }
}

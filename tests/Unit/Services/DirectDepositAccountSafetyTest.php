<?php

namespace Tests\Unit\Services;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\MMRConfig;
use App\Models\Nation;
use App\Services\DirectDepositService;
use App\Services\MMRAssistantService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectDepositAccountSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_deposit_falls_back_when_enrolled_account_is_frozen(): void
    {
        $nation = Nation::factory()->create();
        $frozenAccount = $this->createAccount($nation, 'Frozen', frozen: true);
        $activeAccount = $this->createAccount($nation, 'Active');

        DirectDepositEnrollment::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $frozenAccount->id,
            'previous_tax_id' => 123,
            'enrolled_at' => now(),
        ]);

        $account = app(DirectDepositService::class)->getDepositAccount($nation);

        $this->assertTrue($activeAccount->is($account));
        $this->assertDatabaseMissing('direct_deposit_enrollments', [
            'nation_id' => $nation->id,
            'account_id' => $frozenAccount->id,
        ]);
    }

    public function test_direct_deposit_enroll_rejects_frozen_accounts_before_tax_mutation(): void
    {
        $nation = Nation::factory()->create();
        $frozenAccount = $this->createAccount($nation, 'Frozen', frozen: true);

        $this->expectException(UserErrorException::class);
        $this->expectExceptionMessage('Select an active account that belongs to your nation.');

        app(DirectDepositService::class)->enroll($nation, $frozenAccount);
    }

    public function test_mmr_assistant_ignores_frozen_config_accounts(): void
    {
        SettingService::setMMRAssistantEnabled(true);

        $nation = Nation::factory()->create();
        $frozenAccount = $this->createAccount($nation, 'Frozen', frozen: true);

        MMRConfig::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $frozenAccount->id,
            'enabled' => true,
            'coal_pct' => 100,
        ]);

        $plan = app(MMRAssistantService::class)->plan($nation, 1000);

        $this->assertSame(0.0, $plan['total_spend']);
        $this->assertNull($plan['account']);
    }

    private function createAccount(Nation $nation, string $name, bool $frozen = false): Account
    {
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = $name;
        $account->frozen = $frozen;
        $account->save();

        return $account;
    }
}

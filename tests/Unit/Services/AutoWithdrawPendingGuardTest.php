<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\AutoWithdrawSetting;
use App\Models\Nation;
use App\Models\NationResources;
use App\Models\Transaction;
use App\Services\AutoWithdrawService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\TransactionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoWithdrawPendingGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_withdrawal_key_blocks_duplicate_pending_nation_withdrawals(): void
    {
        [$nation, $account] = $this->createNationWithAccount();
        $resources = $this->resourcePayload(['coal' => 10]);

        $first = TransactionService::createTransaction(
            $resources,
            $nation->id,
            $account->id,
            'withdrawal'
        );

        $this->assertSame(Transaction::PENDING_WITHDRAWAL_KEY_VALUE, $first->fresh()->pending_withdrawal_key);

        try {
            TransactionService::createTransaction(
                $resources,
                $nation->id,
                $account->id,
                'withdrawal'
            );

            $this->fail('Expected duplicate pending withdrawal to violate the unique guard.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('transactions', 1);
        }

        $first->is_pending = false;
        $first->save();

        $this->assertNull($first->fresh()->pending_withdrawal_key);

        $second = TransactionService::createTransaction(
            $resources,
            $nation->id,
            $account->id,
            'withdrawal'
        );

        $this->assertSame(Transaction::PENDING_WITHDRAWAL_KEY_VALUE, $second->fresh()->pending_withdrawal_key);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_auto_withdraw_skips_nations_with_pending_transactions_before_debiting(): void
    {
        config()->set('services.pw.api_key', null);
        SettingService::setAutoWithdrawEnabled(true);

        [$nation, $account] = $this->createNationWithAccount(['coal' => 100]);
        $this->createNationResources($nation, ['coal' => 0]);

        AutoWithdrawSetting::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'resource' => 'coal',
            'threshold' => 100,
            'withdraw_amount' => 50,
            'enabled' => true,
        ]);

        TransactionService::createTransaction(
            $this->resourcePayload(['coal' => 5]),
            $nation->id,
            $account->id,
            'withdrawal'
        );

        app(AutoWithdrawService::class)->evaluateAndExecute($nation->fresh());

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame('100.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertNull(AutoWithdrawSetting::query()->firstOrFail()->last_withdraw_at);
    }

    /**
     * @param  array<string, float|int>  $balances
     * @return array{0: Nation, 1: Account}
     */
    private function createNationWithAccount(array $balances = []): array
    {
        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Auto withdraw account';

        foreach ($balances as $resource => $amount) {
            $account->{$resource} = $amount;
        }

        $account->save();

        return [$nation, $account];
    }

    /**
     * @param  array<string, float|int>  $overrides
     */
    private function createNationResources(Nation $nation, array $overrides = []): NationResources
    {
        return NationResources::query()->create([
            'nation_id' => $nation->id,
            'credits' => 0,
            ...$this->resourcePayload($overrides),
        ]);
    }

    /**
     * @param  array<string, float|int>  $overrides
     * @return array<string, float|int>
     */
    private function resourcePayload(array $overrides = []): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => $overrides[$resource] ?? 0])
            ->all();
    }
}

<?php

namespace Tests\Feature\Console;

use App\Models\Account;
use App\Models\Nation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RapidTransactionDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rapid_transactions_are_reported_without_disabling_the_user_or_freezing_accounts(): void
    {
        $nation = Nation::factory()->create();
        $user = User::factory()->create([
            'nation_id' => $nation->id,
            'disabled' => false,
        ]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Operations';
        $account->frozen = false;
        $account->save();
        $createdAt = now()->startOfSecond();

        foreach ([100, 200] as $money) {
            $transaction = new Transaction;
            $transaction->nation_id = $nation->id;
            $transaction->from_account_id = $account->id;
            $transaction->transaction_type = 'transfer';
            $transaction->money = $money;
            $transaction->is_pending = false;
            $transaction->created_at = $createdAt;
            $transaction->updated_at = $createdAt;
            $transaction->save();
        }

        Log::spy();

        $this->artisan('security:check-rapid-transactions')->assertSuccessful();

        $this->assertFalse((bool) $user->fresh()->disabled);
        $this->assertFalse((bool) $account->fresh()->frozen);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Rapid transactions detected; manual review required.'
                && (int) $context['nation_id'] === $nation->id
                && count($context['transactions']) === 2
        );
    }
}

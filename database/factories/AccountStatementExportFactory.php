<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountStatementExport;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountStatementExport>
 */
class AccountStatementExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nation = Nation::factory()->create();
        $user = User::factory()->create(['nation_id' => $nation->getKey()]);
        $account = new Account;
        $account->nation_id = $nation->getKey();
        $account->name = 'Statement account';
        $account->save();

        return [
            'user_id' => $user->getKey(),
            'account_id' => $account->getKey(),
            'status' => AccountStatementExport::STATUS_PENDING,
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'active_key' => AccountStatementExport::ACTIVE_KEY_VALUE,
            'filters' => [
                'from' => now()->subDays(30)->toDateString(),
                'to' => now()->toDateString(),
                'type' => null,
                'status' => null,
            ],
        ];
    }
}

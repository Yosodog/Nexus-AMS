<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\LotteryDrawing;
use App\Models\LotteryTicket;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LotteryTicket>
 */
class LotteryTicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lottery_drawing_id' => LotteryDrawing::factory(),
            'user_id' => function (): int {
                $nation = Nation::factory()->create();

                return User::factory()->create(['nation_id' => $nation->id])->id;
            },
            'account_id' => function (array $attributes): int {
                $user = User::query()->findOrFail($attributes['user_id']);

                return Account::query()->create([
                    'nation_id' => $user->nation_id,
                    'name' => 'Lottery Account',
                    'money' => 100000,
                ])->id;
            },
            'code' => fn (): string => Str::upper(Str::random(3)),
            'price_paid' => LotteryTicket::PRICE,
            'jackpot_contribution' => LotteryTicket::JACKPOT_CONTRIBUTION,
        ];
    }
}

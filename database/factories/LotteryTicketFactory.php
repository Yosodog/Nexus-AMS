<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\LotteryDrawing;
use App\Models\LotteryTicket;
use App\Models\Nation;
use App\Models\User;
use App\Services\LotteryRandomizer;
use App\Services\LotteryService;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'nation_id' => fn (array $attributes): int => User::query()
                ->findOrFail($attributes['user_id'])
                ->nation_id,
            'account_id' => function (array $attributes): int {
                $user = User::query()->findOrFail($attributes['user_id']);
                $account = new Account;
                $account->nation_id = $user->nation_id;
                $account->name = 'Lottery Account';
                $account->money = 100000;
                $account->save();

                return $account->id;
            },
            'code' => function (): string {
                $value = fake()->unique()->numberBetween(0, LotteryRandomizer::CODE_SPACE_SIZE - 1);

                return strtoupper(str_pad(
                    base_convert((string) $value, 10, 36),
                    LotteryRandomizer::CODE_LENGTH,
                    '0',
                    STR_PAD_LEFT,
                ));
            },
            'price_paid' => SettingService::DEFAULT_LOTTERY_TICKET_PRICE_CENTS / 100,
            'jackpot_contribution' => LotteryService::jackpotContributionCents(
                SettingService::DEFAULT_LOTTERY_TICKET_PRICE_CENTS,
                SettingService::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS,
            ) / 100,
        ];
    }
}

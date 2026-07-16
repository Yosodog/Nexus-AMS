<?php

namespace Database\Factories;

use App\Models\LotteryDrawing;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LotteryDrawing>
 */
class LotteryDrawingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::instance(fake()->unique()->dateTimeBetween('-1 year', '+1 year'));

        return [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addWeek(),
            'status' => LotteryDrawing::STATUS_OPEN,
            'sales_enabled' => true,
            'ticket_price' => SettingService::DEFAULT_LOTTERY_TICKET_PRICE_CENTS / 100,
            'jackpot_basis_points' => SettingService::DEFAULT_LOTTERY_JACKPOT_BASIS_POINTS,
            'jackpot_contribution_per_ticket' => 45000,
            'max_tickets_per_purchase' => SettingService::DEFAULT_LOTTERY_MAX_TICKETS_PER_PURCHASE,
            'max_tickets_per_nation' => SettingService::DEFAULT_LOTTERY_MAX_TICKETS_PER_NATION,
            'ticket_count' => 0,
            'allocation_seed' => bin2hex(random_bytes(32)),
            'next_ticket_sequence' => 0,
            'rollover_amount' => 0,
            'jackpot_amount' => 0,
            'winning_code' => null,
            'drawn_at' => null,
        ];
    }

    public function drawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LotteryDrawing::STATUS_DRAWN,
            'drawn_at' => $attributes['ends_at'],
        ]);
    }
}

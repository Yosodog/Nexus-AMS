<?php

namespace Database\Factories;

use App\Models\RaidTargetClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaidTargetClaim>
 */
class RaidTargetClaimFactory extends Factory
{
    protected $model = RaidTargetClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_nation_id' => fake()->unique()->numberBetween(100_000, 999_999),
            'nation_id' => fake()->numberBetween(100_000, 999_999),
            'user_id' => null,
            'status' => RaidTargetClaim::STATUS_ACTIVE,
            'pending_key' => 1,
            'expires_at' => now()->addHour(),
            'released_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}

<?php

namespace Database\Factories;

use App\Models\RaidTargetProfile;
use App\Services\Economy\EconomyRules;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaidTargetProfile>
 */
class RaidTargetProfileFactory extends Factory
{
    protected $model = RaidTargetProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseline = [];
        $dailyNet = [];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $baseline['baseline_'.$resource] = $resource === 'money'
                ? fake()->numberBetween(1_000_000, 10_000_000)
                : fake()->numberBetween(0, 5_000);
            $dailyNet['daily_net_'.$resource] = $resource === 'money'
                ? fake()->numberBetween(100_000, 1_000_000)
                : fake()->numberBetween(1, 100);
        }

        return [
            'nation_id' => fake()->unique()->numberBetween(100_000, 999_999),
            'nation_name' => fake()->unique()->city().' Nation',
            'leader_name' => fake()->name(),
            'alliance_id' => 0,
            'alliance_position' => null,
            'score' => fake()->randomFloat(2, 1000, 3000),
            'num_cities' => fake()->numberBetween(10, 25),
            'color' => 'gray',
            'beige_turns' => 0,
            'vacation_mode_turns' => 0,
            'last_active' => now()->subDays(fake()->numberBetween(1, 30)),
            'war_policy' => 'TURTLE',
            'soldiers' => fake()->numberBetween(0, 20_000),
            'tanks' => fake()->numberBetween(0, 500),
            'aircraft' => fake()->numberBetween(0, 200),
            'ships' => fake()->numberBetween(0, 20),
            'missiles' => 0,
            'nukes' => 0,
            'highest_city_population' => fake()->numberBetween(50_000, 200_000),
            'highest_city_infra' => fake()->randomFloat(2, 1000, 2500),
            'avg_infra' => fake()->randomFloat(2, 800, 2000),
            'defensive_wars' => 0,
            'offensive_wars' => 0,
            'baseline_kind' => RaidTargetProfile::BASELINE_LOOT,
            'baseline_at' => now()->subDays(3),
            'baseline_attack_id' => null,
            ...$baseline,
            ...$dailyNet,
            'economy_hash' => null,
            'economy_computed_at' => now(),
            'retention_observed' => null,
            'retention_samples' => 0,
            'projected_value' => $baseline['baseline_money'],
            'projected_at' => now(),
            'dirty_at' => null,
            'computed_at' => now(),
        ];
    }

    public function productionOnly(): static
    {
        return $this->state(fn (): array => collect(EconomyRules::RESOURCE_KEYS)
            ->mapWithKeys(fn (string $resource): array => ['baseline_'.$resource => 0])
            ->all() + ['baseline_kind' => RaidTargetProfile::BASELINE_PRODUCTION_ONLY]);
    }
}

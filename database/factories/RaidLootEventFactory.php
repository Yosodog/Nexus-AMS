<?php

namespace Database\Factories;

use App\Models\RaidLootEvent;
use App\Services\Economy\EconomyRules;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaidLootEvent>
 */
class RaidLootEventFactory extends Factory
{
    protected $model = RaidLootEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $resources = [];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $resources[$resource] = $resource === 'money'
                ? fake()->numberBetween(100_000, 1_000_000)
                : fake()->numberBetween(0, 500);
        }

        return [
            'id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'war_id' => fake()->numberBetween(1_000_000, 9_999_999),
            'kind' => RaidLootEvent::KIND_VICTORY,
            'occurred_at' => now()->subDays(2),
            'winner_nation_id' => fake()->numberBetween(100_000, 999_999),
            'loser_nation_id' => fake()->numberBetween(100_000, 999_999),
            'loser_alliance_id' => null,
            'war_type' => 'RAID',
            ...$resources,
            'loot_fraction' => 0.1,
            'fraction_source' => 'modifiers',
        ];
    }

    public function allianceLoot(): static
    {
        return $this->state(fn (): array => [
            'kind' => RaidLootEvent::KIND_ALLIANCE_LOOT,
            'loot_fraction' => null,
            'fraction_source' => 'default',
        ]);
    }
}

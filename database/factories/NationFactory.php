<?php

namespace Database\Factories;

use App\Models\Alliance;
use App\Models\Nation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Nation>
 */
class NationFactory extends Factory
{
    protected $model = Nation::class;

    public function definition(): array
    {
        return [
            'alliance_id' => Alliance::factory(),
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
            'nation_name' => fake()->unique()->city().' Nation',
            'leader_name' => fake()->name(),
            'continent' => 'NA',
            'war_policy' => 'ATTRITION',
            'war_policy_turns' => 0,
            'domestic_policy' => 'MANIFEST_DESTINY',
            'domestic_policy_turns' => 0,
            'color' => 'blue',
            'num_cities' => 5,
            'score' => fake()->randomFloat(2, 500, 3000),
            'update_tz' => 0,
            'population' => fake()->numberBetween(50000, 500000),
            'flag' => fake()->imageUrl(),
            'vacation_mode_turns' => 0,
            'beige_turns' => 0,
            'espionage_available' => true,
            'discord' => null,
            'discord_id' => null,
            'turns_since_last_city' => 0,
            'turns_since_last_project' => 0,
            'projects' => 0,
            'project_bits' => '0',
            'wars_won' => 0,
            'wars_lost' => 0,
            'tax_id' => null,
            'alliance_seniority' => 0,
            'gross_national_income' => 0,
            'gross_domestic_product' => 0,
            'vip' => false,
            'commendations' => 0,
            'denouncements' => 0,
            'offensive_wars_count' => 0,
            'defensive_wars_count' => 0,
            'money_looted' => 0,
            'total_infrastructure_destroyed' => 0,
            'total_infrastructure_lost' => 0,
        ];
    }
}

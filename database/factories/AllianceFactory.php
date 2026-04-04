<?php

namespace Database\Factories;

use App\Models\Alliance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alliance>
 */
class AllianceFactory extends Factory
{
    protected $model = Alliance::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Alliance',
            'acronym' => strtoupper(fake()->lexify('???')),
            'score' => fake()->randomFloat(2, 1000, 100000),
            'color' => 'blue',
            'average_score' => fake()->randomFloat(2, 500, 5000),
            'accept_members' => true,
            'flag' => fake()->imageUrl(),
            'forum_link' => fake()->url(),
            'discord_link' => fake()->url(),
            'wiki_link' => fake()->url(),
            'rank' => fake()->numberBetween(1, 500),
        ];
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RaidNationObservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nation_id' => fake()->numberBetween(1, 1000000),
            'observed_at' => now(),
            'valid_from' => fn (array $attributes) => $attributes['observed_at'],
            'confirmed_through' => fn (array $attributes) => $attributes['observed_at'],
            'payload' => [],
            'provenance_war_ids' => [],
        ];
    }
}

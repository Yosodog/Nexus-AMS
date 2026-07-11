<?php

namespace Database\Factories;

use App\Enums\BlockadeReliefStatus;
use App\Models\BlockadeReliefRequest;
use App\Models\Nation;
use App\Models\War;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockadeReliefRequest>
 */
class BlockadeReliefRequestFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (BlockadeReliefRequest $request): void {
            War::query()->firstOrCreate(['id' => $request->war_id], [
                'att_id' => $request->requester_nation_id,
                'def_id' => $request->blockading_nation_id,
                'reason' => 'Blockade relief factory war',
                'turns_left' => 60,
                'naval_blockade' => $request->blockading_nation_id,
            ]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_nation_id' => Nation::factory(),
            'war_id' => fake()->unique()->numberBetween(1000000, 9999999),
            'blockading_nation_id' => Nation::factory(),
            'status' => BlockadeReliefStatus::Pending,
            'pending_key' => 1,
            'deadline_at' => now()->addHours(6),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\RaidAllianceProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaidAllianceProfile>
 */
class RaidAllianceProfileFactory extends Factory
{
    protected $model = RaidAllianceProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alliance_id' => fake()->unique()->numberBetween(1_000, 99_999),
            'alliance_score' => fake()->randomFloat(2, 50_000, 500_000),
            'raids_received_30d' => 0,
            'countered_30d' => 0,
            'counter_rate' => 0.1,
            'computed_at' => now(),
        ];
    }

    /**
     * @param  array<string, float>  $bank
     */
    public function withBank(array $bank): static
    {
        return $this->state(fn (): array => collect($bank)
            ->mapWithKeys(fn (float|int $amount, string $resource): array => ['bank_'.$resource => $amount])
            ->all() + ['bank_evidence_at' => now()->subDay()]);
    }
}

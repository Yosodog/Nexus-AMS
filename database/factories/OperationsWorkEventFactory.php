<?php

namespace Database\Factories;

use App\Models\OperationsWorkCoordination;
use App\Models\OperationsWorkEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationsWorkEvent>
 */
class OperationsWorkEventFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (OperationsWorkEvent $event): void {
            $coordination = $event->coordination;

            $event->forceFill([
                'work_key' => $coordination->work_key,
                'occurrence_key' => $coordination->occurrence_key,
                'source_type' => $coordination->source_type,
            ])->saveQuietly();
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
            'coordination_id' => OperationsWorkCoordination::factory(),
            'work_key' => 'applications:'.fake()->unique()->numberBetween(1, 999999),
            'occurrence_key' => fake()->unique()->uuid(),
            'source_type' => 'applications',
            'team_key' => 'internal_affairs',
            'event_type' => 'discovered',
            'actor_user_id' => User::factory(),
            'subject_user_id' => null,
            'correlation_id' => fake()->uuid(),
            'idempotency_key' => null,
            'metadata' => ['source' => 'factory'],
            'occurred_at' => now(),
        ];
    }
}

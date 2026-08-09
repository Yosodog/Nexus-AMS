<?php

namespace Database\Factories;

use App\Models\OperationsWorkCoordination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationsWorkCoordination>
 */
class OperationsWorkCoordinationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstSeenAt = now()->subHours(fake()->numberBetween(1, 72));

        return [
            'work_key' => 'applications:'.fake()->unique()->numberBetween(1, 999999),
            'occurrence_key' => fake()->unique()->uuid(),
            'source_type' => 'applications',
            'source_fingerprint' => hash('sha256', fake()->uuid()),
            'team_override_key' => null,
            'assignee_user_id' => null,
            'assigned_by_user_id' => null,
            'assigned_at' => null,
            'triage_acknowledged_at' => null,
            'triage_acknowledged_by_user_id' => null,
            'escalated_at' => null,
            'escalated_by_user_id' => null,
            'escalation_reason' => null,
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => now(),
            'source_updated_at' => now(),
            'first_action_at' => null,
            'last_activity_at' => null,
            'closed_at' => null,
            'lock_version' => 1,
            'active_key' => OperationsWorkCoordination::ACTIVE_KEY_VALUE,
        ];
    }
}

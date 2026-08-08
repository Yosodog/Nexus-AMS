<?php

namespace Database\Factories;

use App\Enums\AlertSensitivity;
use App\Enums\AlertSeverity;
use App\Models\AlertOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AlertOccurrence>
 */
class AlertOccurrenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_key' => 'nation.alliance.changed',
            'schema_version' => 1,
            'source_type' => 'factory',
            'source_id' => (string) Str::uuid(),
            'subject_type' => 'nation',
            'subject_id' => (string) fake()->numberBetween(1, 999_999),
            'deep_link_path' => '/alerts/activity',
            'severity' => AlertSeverity::Normal,
            'sensitivity' => AlertSensitivity::Public,
            'payload' => ['label' => fake()->words(3, true)],
            'occurred_at' => now(),
            'observed_at' => now(),
            'received_at' => now(),
            'dedupe_key' => hash('sha256', (string) Str::uuid()),
            'is_test' => false,
        ];
    }
}

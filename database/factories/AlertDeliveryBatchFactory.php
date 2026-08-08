<?php

namespace Database\Factories;

use App\Enums\AlertBatchStatus;
use App\Enums\AlertDestinationKind;
use App\Models\AlertDeliveryBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AlertDeliveryBatch>
 */
class AlertDeliveryBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'destination_kind' => AlertDestinationKind::DiscordDm,
            'status' => AlertBatchStatus::Pending,
            'template_key' => 'member_alert_v1',
            'schema_version' => 1,
            'dedupe_key' => hash('sha256', (string) Str::uuid()),
            'destination_snapshot' => [
                'type' => 'dm',
                'discord_user_id' => '4'.fake()->numerify('#################'),
            ],
            'is_test' => false,
        ];
    }
}

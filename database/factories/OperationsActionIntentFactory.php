<?php

namespace Database\Factories;

use App\Models\OperationsActionIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OperationsActionIntent>
 */
class OperationsActionIntentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'actor_user_id' => User::factory(),
            'action' => 'audit.acknowledge',
            'payload' => ['work_key' => 'audits:1'],
            'preview_fingerprint' => hash('sha256', (string) Str::uuid()),
            'status' => OperationsActionIntent::STATUS_DRAFT,
            'result' => null,
            'expires_at' => now()->addMinutes(5),
            'executed_at' => null,
            'cancelled_at' => null,
        ];
    }
}

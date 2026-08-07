<?php

namespace Database\Factories;

use App\Enums\MemberInactivityAutomation;
use App\Enums\MemberInactivityExceptionCategory;
use App\Models\MemberInactivityException;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberInactivityException>
 */
class MemberInactivityExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nation_id' => Nation::factory(),
            'category' => MemberInactivityExceptionCategory::Vacation,
            'starts_at' => now()->startOfMinute(),
            'ends_at' => now()->addWeek()->startOfMinute(),
            'timezone' => 'UTC',
            'member_reason' => 'Approved leave temporarily pauses the selected inactivity automations.',
            'private_notes' => 'Verified by staff.',
            'affected_automations' => collect(MemberInactivityAutomation::cases())->pluck('value')->all(),
            'approved_by_user_id' => User::factory()->admin(),
            'approved_at' => now(),
            'last_reviewed_by_user_id' => fn (array $attributes): int => (int) $attributes['approved_by_user_id'],
            'last_reviewed_at' => now(),
        ];
    }
}

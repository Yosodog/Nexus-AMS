<?php

namespace Database\Factories;

use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\MembershipStatus;
use App\Models\FederationCoalition;
use App\Models\FederationCoalitionMembership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FederationCoalitionMembership>
 */
class FederationCoalitionMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'federation_coalition_id' => FederationCoalition::factory(),
            'installation_id' => (string) Str::ulid(),
            'federation_link_id' => null,
            'role' => CoalitionRole::Member->value,
            'status' => MembershipStatus::Pending->value,
            'roster_revision' => 1,
            'joined_at' => null,
            'expires_at' => null,
            'removed_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
            'removed_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Pending->value,
            'joined_at' => null,
            'removed_at' => null,
        ]);
    }

    public function coordinator(): static
    {
        return $this->active()->state(fn (): array => [
            'role' => CoalitionRole::Coordinator->value,
        ]);
    }

    public function admin(): static
    {
        return $this->active()->state(fn (): array => [
            'role' => CoalitionRole::Admin->value,
        ]);
    }

    public function observer(): static
    {
        return $this->active()->state(fn (): array => [
            'role' => CoalitionRole::Observer->value,
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Removed->value,
            'joined_at' => now()->subDay(),
            'removed_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Expired->value,
            'joined_at' => now()->subDays(2),
            'expires_at' => now()->subMinute(),
            'removed_at' => null,
        ]);
    }
}

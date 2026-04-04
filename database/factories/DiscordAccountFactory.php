<?php

namespace Database\Factories;

use App\Models\DiscordAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscordAccount>
 */
class DiscordAccountFactory extends Factory
{
    protected $model = DiscordAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'discord_id' => (string) fake()->unique()->numerify('##################'),
            'discord_username' => fake()->userName(),
            'linked_at' => now(),
            'unlinked_at' => null,
        ];
    }

    public function unlinked(): static
    {
        return $this->state(fn (): array => [
            'unlinked_at' => now(),
        ]);
    }
}

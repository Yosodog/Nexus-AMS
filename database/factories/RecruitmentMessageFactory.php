<?php

namespace Database\Factories;

use App\Models\RecruitmentMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecruitmentMessage>
 */
class RecruitmentMessageFactory extends Factory
{
    protected $model = RecruitmentMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'type' => 'variant',
            'subject' => Str::limit(fake()->sentence(4), 50, ''),
            'message' => '<p>'.fake()->paragraph().'</p><p><a href="{apply_link}">Apply here</a></p>',
            'tracking_key' => Str::lower(Str::random(10)),
            'is_active' => true,
            'lifetime_sends' => 0,
            'lifetime_clicks' => 0,
            'current_sends' => 0,
            'current_clicks' => 0,
        ];
    }

    /**
     * Indicate that the recruitment message is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the message is the follow-up template.
     */
    public function followUp(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Follow-up Message',
            'type' => 'follow_up',
            'is_active' => false,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledTaskRun>
 */
class ScheduledTaskRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subSeconds(fake()->numberBetween(1, 300));

        return [
            'task_identifier' => 'artisan:test:heartbeat',
            'task_mutex_hash' => hash('sha256', fake()->uuid()),
            'status' => ScheduledTaskRunStatus::Success,
            'scheduled_at' => $startedAt,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'duration_ms' => $startedAt->diffInMilliseconds(now()),
            'exit_code' => 0,
            'hostname' => 'scheduler-'.fake()->slug(2),
            'correlation_id' => fake()->uuid(),
            'exception_class' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => ScheduledTaskRunStatus::Running,
            'finished_at' => null,
            'duration_ms' => null,
            'exit_code' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ScheduledTaskRunStatus::Failure,
            'exit_code' => 1,
            'exception_class' => \RuntimeException::class,
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn (): array => [
            'status' => ScheduledTaskRunStatus::Skipped,
            'started_at' => null,
            'duration_ms' => null,
            'exit_code' => null,
        ]);
    }

    public function overlapping(): static
    {
        return $this->state(fn (): array => [
            'status' => ScheduledTaskRunStatus::Overlap,
            'started_at' => null,
            'duration_ms' => 0,
            'exit_code' => null,
        ]);
    }
}

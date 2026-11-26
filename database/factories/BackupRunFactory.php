<?php

namespace Database\Factories;

use App\Enums\BackupRunStatus;
use App\Enums\BackupRunTrigger;
use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    /**
     * Define the model's default state: a finished, successful scheduled run.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subHours(fake()->numberBetween(1, 48));

        return [
            'trigger' => BackupRunTrigger::Scheduled,
            'status' => BackupRunStatus::Succeeded,
            'pending_key' => null,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addMinutes(3),
            'backup_path' => 'Nexus/'.$startedAt->format('Y-m-d-H-i-s').'.zip',
            'backup_size_bytes' => fake()->numberBetween(50_000_000, 200_000_000),
            'error_message' => null,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'trigger' => BackupRunTrigger::Manual,
            'status' => BackupRunStatus::Queued,
            'pending_key' => 1,
            'started_at' => null,
            'finished_at' => null,
            'backup_path' => null,
            'backup_size_bytes' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => BackupRunStatus::Running,
            'pending_key' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
            'backup_path' => null,
            'backup_size_bytes' => null,
        ]);
    }

    public function failed(string $message = 'Backup failed.'): static
    {
        return $this->state(fn (): array => [
            'status' => BackupRunStatus::Failed,
            'pending_key' => null,
            'backup_path' => null,
            'backup_size_bytes' => null,
            'error_message' => $message,
        ]);
    }
}

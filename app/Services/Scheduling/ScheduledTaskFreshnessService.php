<?php

namespace App\Services\Scheduling;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

class ScheduledTaskFreshnessService
{
    /**
     * @return Collection<string, ScheduledTaskFreshness>
     */
    public function snapshot(?CarbonImmutable $asOf = null): Collection
    {
        $contracts = $this->contracts();

        if ($contracts === []) {
            return collect();
        }

        $lastSuccesses = ScheduledTaskRun::query()
            ->whereIn('task_identifier', array_keys($contracts))
            ->where('status', ScheduledTaskRunStatus::Success->value)
            ->whereNotNull('finished_at')
            ->selectRaw('task_identifier, MAX(finished_at) AS last_succeeded_at')
            ->groupBy('task_identifier')
            ->pluck('last_succeeded_at', 'task_identifier');

        $asOf ??= now()->toImmutable();

        return collect($contracts)->map(function (array $contract, string $taskIdentifier) use ($lastSuccesses, $asOf): ScheduledTaskFreshness {
            $lastSucceededAt = $this->toImmutable($lastSuccesses->get($taskIdentifier));
            $expectedBy = $lastSucceededAt?->addMinutes($contract['maximum_age_minutes']);

            return new ScheduledTaskFreshness(
                taskIdentifier: $taskIdentifier,
                label: $contract['label'],
                maximumAgeMinutes: $contract['maximum_age_minutes'],
                lastSucceededAt: $lastSucceededAt,
                expectedBy: $expectedBy,
                isOverdue: $expectedBy === null || $expectedBy->lte($asOf),
            );
        });
    }

    /**
     * @return Collection<string, ScheduledTaskFreshness>
     */
    public function overdue(?CarbonImmutable $asOf = null): Collection
    {
        return $this->snapshot($asOf)
            ->filter(fn (ScheduledTaskFreshness $freshness): bool => $freshness->isOverdue);
    }

    /**
     * @return array<string, array{label: string, maximum_age_minutes: int}>
     */
    private function contracts(): array
    {
        $configured = config('scheduler_lifecycle.freshness_contracts', []);

        if (! is_array($configured)) {
            return [];
        }

        $contracts = [];

        foreach ($configured as $taskIdentifier => $contract) {
            if (! is_string($taskIdentifier) || $taskIdentifier === '' || ! is_array($contract)) {
                continue;
            }

            $maximumAgeMinutes = (int) ($contract['maximum_age_minutes'] ?? 0);

            if ($maximumAgeMinutes < 1) {
                continue;
            }

            $label = trim((string) ($contract['label'] ?? $taskIdentifier));
            $contracts[$taskIdentifier] = [
                'label' => $label !== '' ? $label : $taskIdentifier,
                'maximum_age_minutes' => $maximumAgeMinutes,
            ];
        }

        return $contracts;
    }

    private function toImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}

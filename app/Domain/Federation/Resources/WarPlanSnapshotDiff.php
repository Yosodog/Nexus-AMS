<?php

namespace App\Domain\Federation\Resources;

use App\Domain\Federation\DTO\WarPlanSnapshotV1;

final class WarPlanSnapshotDiff
{
    /**
     * @return array{
     *   changed_fields:list<string>,
     *   targets_added:list<int>,
     *   targets_removed:list<int>,
     *   targets_changed:list<int>
     * }
     */
    public function between(?WarPlanSnapshotV1 $previous, WarPlanSnapshotV1 $current): array
    {
        if ($previous === null) {
            return [
                'changed_fields' => ['initial publication'],
                'targets_added' => array_map(fn ($target): int => $target->targetNationId, $current->targets),
                'targets_removed' => [],
                'targets_changed' => [],
            ];
        }

        $changedFields = [];

        foreach ([
            'title' => [$previous->title, $current->title],
            'wave label' => [$previous->waveLabel, $current->waveLabel],
            'recipient instructions' => [$previous->recipientInstructions, $current->recipientInstructions],
            'expiry' => [$previous->expiresAt->toIso8601String(), $current->expiresAt->toIso8601String()],
        ] as $label => [$before, $after]) {
            if ($before !== $after) {
                $changedFields[] = $label;
            }
        }

        $beforeTargets = collect($previous->targets)->keyBy(fn ($target): int => $target->targetNationId);
        $afterTargets = collect($current->targets)->keyBy(fn ($target): int => $target->targetNationId);
        $added = $afterTargets->keys()->diff($beforeTargets->keys())->map(fn (mixed $id): int => (int) $id);
        $removed = $beforeTargets->keys()->diff($afterTargets->keys())->map(fn (mixed $id): int => (int) $id);
        $changed = $afterTargets->keys()
            ->intersect($beforeTargets->keys())
            ->filter(function (mixed $id) use ($beforeTargets, $afterTargets): bool {
                return $beforeTargets->get($id)->toArray() !== $afterTargets->get($id)->toArray();
            })
            ->map(fn (mixed $id): int => (int) $id);

        return [
            'changed_fields' => $changedFields,
            'targets_added' => $added->values()->all(),
            'targets_removed' => $removed->values()->all(),
            'targets_changed' => $changed->values()->all(),
        ];
    }
}

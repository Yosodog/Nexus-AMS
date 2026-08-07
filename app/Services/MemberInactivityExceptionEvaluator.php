<?php

namespace App\Services;

use App\Enums\MemberInactivityAutomation;
use App\Models\MemberInactivityException;
use App\Models\Nation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MemberInactivityExceptionEvaluator
{
    /**
     * @return Collection<int, MemberInactivityException>
     */
    public function activeForNation(Nation|int $nation, ?CarbonInterface $at = null): Collection
    {
        $nationId = $nation instanceof Nation ? (int) $nation->getKey() : $nation;

        return MemberInactivityException::query()
            ->activeAt(($at ?? now())->toMutable())
            ->where('nation_id', $nationId)
            ->orderBy('ends_at')
            ->get();
    }

    /**
     * @param  list<int>  $nationIds
     * @return Collection<int, array<string, true>>
     */
    public function activeSuppressionMap(array $nationIds, ?CarbonInterface $at = null): Collection
    {
        if ($nationIds === []) {
            return collect();
        }

        $exceptions = MemberInactivityException::query()
            ->activeAt(($at ?? now())->toMutable())
            ->whereIn('nation_id', $nationIds)
            ->get(['id', 'nation_id', 'affected_automations']);

        /** @var Collection<int, array<string, true>> $map */
        $map = collect();

        foreach ($exceptions as $exception) {
            $suppressed = $map->get((int) $exception->nation_id, []);

            foreach ($exception->affected_automations as $automation) {
                $suppressed[$automation->value] = true;
            }

            $map->put((int) $exception->nation_id, $suppressed);
        }

        return $map;
    }

    /**
     * @return list<int>
     */
    public function nationIdsSuppressing(
        MemberInactivityAutomation $automation,
        ?CarbonInterface $at = null,
    ): array {
        return MemberInactivityException::query()
            ->activeAt(($at ?? now())->toMutable())
            ->get(['id', 'nation_id', 'affected_automations'])
            ->filter(fn (MemberInactivityException $exception): bool => $exception->affected_automations->contains($automation))
            ->pluck('nation_id')
            ->map(fn (mixed $nationId): int => (int) $nationId)
            ->unique()
            ->values()
            ->all();
    }

    public function suppresses(
        Nation|int $nation,
        MemberInactivityAutomation $automation,
        ?CarbonInterface $at = null,
    ): bool {
        $nationId = $nation instanceof Nation ? (int) $nation->getKey() : $nation;
        $map = $this->activeSuppressionMap([$nationId], $at);

        return isset($map->get($nationId, [])[$automation->value]);
    }

    /**
     * @return list<array{
     *     category: string,
     *     reason: string,
     *     starts_at: CarbonInterface,
     *     ends_at: CarbonInterface,
     *     timezone: string,
     *     effects: list<string>
     * }>
     */
    public function memberVisibleEffectsForNation(Nation|int $nation, ?CarbonInterface $at = null): array
    {
        return $this->activeForNation($nation, $at)
            ->map(fn (MemberInactivityException $exception): array => [
                'category' => $exception->category->label(),
                'reason' => $exception->member_reason,
                'starts_at' => $exception->starts_at,
                'ends_at' => $exception->ends_at,
                'timezone' => $exception->timezone,
                'effects' => $exception->affected_automations
                    ->map(fn (MemberInactivityAutomation $automation): string => $automation->label())
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}

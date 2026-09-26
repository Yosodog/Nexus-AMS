<?php

namespace App\Services\Raids;

use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\RaidPolicyService;

/**
 * Live declaration check for one attacker/target pair against the Politics & War API.
 */
final class RaidAvailabilityService
{
    private const OFFENSIVE_SLOTS_FULL = 'All your offensive slots are occupied.';

    public function __construct(
        private QueryService $queries,
        private RaidPolicyService $policy,
    ) {}

    /**
     * @return array{eligible: bool, planning_only: bool, reasons: list<string>, defensive_wars: int, offensive_wars: int, offensive_capacity: int, checked_at: string}
     */
    public function check(int $attackerId, int $targetId): array
    {
        $nations = $this->nations([$attackerId, $targetId]);
        $wars = $this->activeWars([$attackerId, $targetId]);
        $attacker = $nations[$attackerId] ?? null;
        $target = $nations[$targetId] ?? null;
        $checkedAt = now()->toIso8601String();

        if ($attacker === null || $target === null) {
            return [
                'eligible' => false,
                'planning_only' => false,
                'reasons' => ['Target is unavailable.'],
                'defensive_wars' => 0,
                'offensive_wars' => 0,
                'offensive_capacity' => 0,
                'checked_at' => $checkedAt,
            ];
        }

        $reasons = [];
        $attackerScore = (float) ($attacker['score'] ?? 0);
        $targetScore = (float) ($target['score'] ?? 0);
        $minimumScore = $attackerScore * (float) config('milcom.game_rules.declaration_score_minimum_multiplier');
        $maximumScore = $attackerScore * (float) config('milcom.game_rules.declaration_score_maximum_multiplier');

        if ($attackerId === $targetId || $targetScore < $minimumScore || $targetScore > $maximumScore) {
            $reasons[] = 'Target is outside your declaration range.';
        }

        $allianceId = (int) ($target['alliance_id'] ?? 0);

        if ($allianceId > 0 && in_array($allianceId, $this->policy->protectedAllianceIds(), true)) {
            $reasons[] = 'Alliance raid policy protects this target.';
        }

        if ((int) ($target['vacation_mode_turns'] ?? 0) > 0
            || (int) ($target['beige_turns'] ?? 0) > 0
            || strtolower((string) ($target['color'] ?? '')) === 'beige') {
            $reasons[] = 'Target is protected or in vacation mode.';
        }

        $defensiveWars = max(
            (int) ($target['defensive_wars_count'] ?? 0),
            count(array_filter($wars, fn (array $war): bool => (int) $war['def_id'] === $targetId)),
        );

        if ($defensiveWars >= 3) {
            $reasons[] = 'All defensive slots are occupied.';
        }

        $alreadyFighting = array_filter($wars, fn (array $war): bool => ((int) $war['att_id'] === $attackerId && (int) $war['def_id'] === $targetId)
            || ((int) $war['att_id'] === $targetId && (int) $war['def_id'] === $attackerId));

        if ($alreadyFighting !== []) {
            $reasons[] = 'You are already fighting this target.';
        }

        $offensiveCapacity = (int) config('milcom.game_rules.base_offensive_slots');

        foreach ((array) config('milcom.game_rules.offensive_slot_projects') as $project => $modifier) {
            if ((bool) ($attacker[$project] ?? false)) {
                $offensiveCapacity += (int) $modifier;
            }
        }

        $offensiveWars = max(
            (int) ($attacker['offensive_wars_count'] ?? 0),
            count(array_filter($wars, fn (array $war): bool => (int) $war['att_id'] === $attackerId)),
        );

        if ($offensiveWars >= $offensiveCapacity) {
            $reasons[] = self::OFFENSIVE_SLOTS_FULL;
        }

        if ((int) ($attacker['vacation_mode_turns'] ?? 0) > 0) {
            $reasons[] = 'You cannot declare while in vacation mode.';
        }

        return [
            'eligible' => $reasons === [],
            'planning_only' => $reasons === [self::OFFENSIVE_SLOTS_FULL],
            'reasons' => $reasons,
            'defensive_wars' => $defensiveWars,
            'offensive_wars' => $offensiveWars,
            'offensive_capacity' => $offensiveCapacity,
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function nations(array $ids): array
    {
        $query = (new GraphQLQueryBuilder)->setRootField('nations')
            ->addArgument('id', $ids)
            ->addArgument('first', count($ids))
            ->addNestedField('data', fn (GraphQLQueryBuilder $builder) => $builder->addFields([
                'id', 'score', 'alliance_id', 'vacation_mode_turns', 'beige_turns', 'color',
                'defensive_wars_count', 'offensive_wars_count', 'pirate_economy', 'advanced_pirate_economy',
            ]));

        return collect($this->decode($this->queries->sendQuery($query, handlePagination: false)))
            ->keyBy(fn (array $nation): int => (int) ($nation['id'] ?? 0))
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function activeWars(array $ids): array
    {
        $query = (new GraphQLQueryBuilder)->setRootField('wars')
            ->addArgument(['nation_id' => $ids, 'active' => true, 'first' => 100])
            ->addNestedField('data', fn (GraphQLQueryBuilder $builder) => $builder->addFields(['id', 'att_id', 'def_id']));

        return $this->decode($this->queries->sendQuery($query, handlePagination: false));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(mixed $response): array
    {
        $decoded = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter((array) $decoded, 'is_array'));
    }
}

<?php

namespace App\Services;

use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use App\Services\Economy\EconomyRules;
use App\Services\Economy\MarketValuationService;
use App\Services\World\WorldWriteGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RaidIntelligenceRefreshService
{
    public function __construct(
        private QueryService $queries,
        private WorldWriteGuard $guard,
        private RaidIntelligenceService $intelligence,
        private MarketValuationService $valuation,
        private RaidNationSnapshotCompactor $compactor,
    ) {}

    /** @return list<string> */
    public static function publicFields(): array
    {
        return array_values(array_diff(SelectionSetHelper::nationSet(), [
            ...EconomyRules::RESOURCE_KEYS, 'credits', 'discord', 'discord_id', 'tax_id',
            'espionage_available', 'spies', 'spies_today',
        ]));
    }

    /** @param list<int> $nationIds */
    public function refresh(array $nationIds, bool $force = false): void
    {
        $this->guard->assertCanWrite(RaidNationObservation::class);
        $nationIds = array_values(array_unique(array_filter(array_map('intval', $nationIds), fn (int $id): bool => $id > 0)));
        if (! $force) {
            $fresh = RaidNationObservation::query()->whereIn('nation_id', $nationIds)
                ->where('current_key', 1)
                ->where('observed_at', '>=', now()->subSeconds((int) config('raids.fresh_seconds', 300)))
                ->get(['nation_id', 'observed_at'])->groupBy('nation_id');
            $nationIds = array_values(array_filter($nationIds, function (int $id) use ($fresh): bool {
                $last = $fresh->get($id)?->max('observed_at');

                return $last === null || RaidAttackObservation::query()
                    ->where(fn ($query) => $query->where('att_id', $id)->orWhere('def_id', $id))
                    ->where('occurred_at', '>=', $last)->exists();
            }));
        }
        foreach (array_chunk($nationIds, max(1, (int) config('raids.batch_size', 25))) as $ids) {
            $wars = $this->history($ids);
            $evidenceNationIds = $this->evidenceNationIds($ids);
            $query = (new GraphQLQueryBuilder)->setRootField('nations')->addArgument('id', $ids)
                ->addArgument('first', count($ids))
                ->addNestedField('data', function (GraphQLQueryBuilder $builder): void {
                    $builder->addFields(self::publicFields())
                        ->addNestedField('cities', fn (GraphQLQueryBuilder $city) => $city->addFields(SelectionSetHelper::citySet()))
                        ->addNestedField('alliance', fn (GraphQLQueryBuilder $alliance) => $alliance->addFields(['id', 'name', 'score']))
                        ->addNestedField('bounties', fn (GraphQLQueryBuilder $bounty) => $bounty->addFields(['id', 'date', 'amount', 'type']))
                        ->addNestedField('military_research', fn (GraphQLQueryBuilder $research) => $research->addFields(SelectionSetHelper::militaryResearchSet()));
                });
            $rows = $this->queries->sendQuery($query, handlePagination: false);
            $observedAt = CarbonImmutable::now();
            $stateChanged = false;
            foreach ($rows as $raw) {
                $payload = json_decode(json_encode($raw, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                $id = (int) ($payload['id'] ?? 0);
                if (! in_array($id, $ids, true)) {
                    continue;
                }
                $payload = Arr::only($payload, [...self::publicFields(), 'cities', 'military_research', 'alliance', 'bounties']);
                $payload['military_research'] = Arr::only((array) ($payload['military_research'] ?? []), SelectionSetHelper::militaryResearchSet());
                $payload['alliance'] = isset($payload['alliance']) ? Arr::only($payload['alliance'], ['id', 'name', 'score']) : null;
                $payload['bounties'] = array_map(fn (array $bounty): array => Arr::only($bounty, ['id', 'date', 'amount', 'type']), $payload['bounties'] ?? []);
                $payload['cities'] = array_map(fn (array $city): array => Arr::only($city, SelectionSetHelper::citySet()), $payload['cities'] ?? []);
                $payload['active_wars'] = array_values(array_filter($wars, fn (array $war): bool => ((int) $war['att_id'] === $id || (int) $war['def_id'] === $id)
                    && empty($war['end_date']) && (int) ($war['turns_left'] ?? 0) > 0 && (int) ($war['winner_id'] ?? 0) === 0));
                $payload['is_fortified'] = collect($payload['active_wars'])->contains(
                    static fn (array $war): bool => (int) ($war['att_id'] ?? 0) === $id
                        ? (bool) ($war['att_fortify'] ?? false)
                        : (bool) ($war['def_fortify'] ?? false),
                );
                $payload['history_complete'] = (bool) Cache::get('raid-history-complete:'.implode(',', $ids), false);
                $provenance = RaidAttackObservation::query()->where(fn ($q) => $q->where('att_id', $id)->orWhere('def_id', $id))
                    ->where('occurred_at', '<=', now())->distinct()->pluck('war_id')->map(fn ($v): int => (int) $v)->all();
                try {
                    $payload = $this->intelligence->withEconomy($payload, $this->valuation->current(), $observedAt);
                } catch (\Throwable) {
                    $payload['economy_unavailable'] = 'Market or economy context is not available.';
                }
                $payload = $this->compactor->compact($payload);
                $stateChanged = $this->persistObservation(
                    $id,
                    $payload,
                    $provenance,
                    $observedAt,
                    in_array($id, $evidenceNationIds, true),
                ) || $stateChanged;
            }
            if ($stateChanged) {
                Cache::store(config('raids.intelligence_cache_store'))->forever('raid-intelligence:revision', (string) Str::uuid());
            }
        }
    }

    /** @param list<int> $ids @return list<int> */
    private function evidenceNationIds(array $ids): array
    {
        $cutoff = now()->subDays((int) config('raids.checkpoint_retention_days', 31));
        $attackerIds = RaidAttackObservation::query()
            ->whereIn('att_id', $ids)
            ->where('occurred_at', '>=', $cutoff)
            ->pluck('att_id');
        $defenderIds = RaidAttackObservation::query()
            ->whereIn('def_id', $ids)
            ->where('occurred_at', '>=', $cutoff)
            ->pluck('def_id');

        return $attackerIds->merge($defenderIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $payload @param list<int> $provenance */
    private function persistObservation(
        int $nationId,
        array $payload,
        array $provenance,
        CarbonImmutable $observedAt,
        bool $hasRecentEvidence,
    ): bool {
        $stateHash = $this->compactor->hash($payload, $provenance);

        return RaidNationObservation::query()->getConnection()->transaction(function () use (
            $nationId,
            $payload,
            $provenance,
            $observedAt,
            $hasRecentEvidence,
            $stateHash,
        ): bool {
            $current = RaidNationObservation::query()
                ->where('nation_id', $nationId)
                ->where('current_key', 1)
                ->lockForUpdate()
                ->first();
            if ($current === null) {
                RaidNationObservation::query()->create($this->observationAttributes(
                    $nationId,
                    $payload,
                    $provenance,
                    $stateHash,
                    $observedAt,
                ));

                return true;
            }

            if (is_string($current->state_hash) && hash_equals($current->state_hash, $stateHash)) {
                $current->forceFill([
                    'observed_at' => $observedAt,
                    'confirmed_through' => $observedAt,
                    'payload' => $payload,
                    'provenance_war_ids' => $provenance,
                    'source' => 'public_api',
                ])->save();

                return false;
            }

            $preserveHistory = $hasRecentEvidence
                || $this->hasActiveWar($current->payload ?? [])
                || $this->hasActiveWar($payload);
            if ($preserveHistory) {
                $current->forceFill([
                    'current_key' => null,
                    'payload' => $this->compactor->compact($current->payload ?? []),
                    'valid_from' => $current->valid_from ?? $current->observed_at,
                    'confirmed_through' => $current->confirmed_through ?? $current->observed_at,
                ])->save();
                RaidNationObservation::query()->create($this->observationAttributes(
                    $nationId,
                    $payload,
                    $provenance,
                    $stateHash,
                    $observedAt,
                ));

                return true;
            }

            $current->forceFill($this->observationAttributes(
                $nationId,
                $payload,
                $provenance,
                $stateHash,
                $observedAt,
            ))->save();

            return true;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $provenance
     * @return array<string, mixed>
     */
    private function observationAttributes(
        int $nationId,
        array $payload,
        array $provenance,
        string $stateHash,
        CarbonImmutable $observedAt,
    ): array {
        return [
            'nation_id' => $nationId,
            'current_key' => 1,
            'state_hash' => $stateHash,
            'observed_at' => $observedAt,
            'valid_from' => $observedAt,
            'confirmed_through' => $observedAt,
            'payload' => $payload,
            'provenance_war_ids' => $provenance,
            'source' => 'public_api',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function hasActiveWar(array $payload): bool
    {
        return collect($payload['active_wars'] ?? [])->contains(static fn (mixed $war): bool => is_array($war)
            && empty($war['end_date'])
            && (int) ($war['turns_left'] ?? 1) > 0
            && (int) ($war['winner_id'] ?? 0) === 0);
    }

    /**
     * Read current declaration eligibility without downloading attack history or
     * writing public world tables from a hosted tenant.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function currentAvailability(array $ids): array
    {
        $query = (new GraphQLQueryBuilder)->setRootField('nations')
            ->addArgument('id', $ids)->addArgument('first', count($ids))
            ->addNestedField('data', fn (GraphQLQueryBuilder $builder) => $builder->addFields([
                'id', 'score', 'alliance_id', 'vacation_mode_turns', 'beige_turns', 'color',
                'defensive_wars_count', 'offensive_wars_count', 'pirate_economy', 'advanced_pirate_economy',
            ]));
        $rows = $this->queries->sendQuery($query, handlePagination: false);
        $warsQuery = (new GraphQLQueryBuilder)->setRootField('wars')
            ->addArgument(['nation_id' => $ids, 'active' => true, 'first' => 100])
            ->addNestedField('data', fn (GraphQLQueryBuilder $builder) => $builder->addFields(['id', 'att_id', 'def_id']));
        $wars = json_decode(json_encode($this->queries->sendQuery($warsQuery, handlePagination: false), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $result = [];
        foreach ($rows as $raw) {
            $nation = json_decode(json_encode($raw, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $id = (int) ($nation['id'] ?? 0);
            if (in_array($id, $ids, true)) {
                $nation['observed_at'] = now()->toIso8601String();
                $nation['active_wars'] = array_values(array_filter($wars, fn (array $war): bool => (int) $war['att_id'] === $id || (int) $war['def_id'] === $id));
                $result[$id] = $nation;
            }
        }

        return $result;
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function history(array $ids): array
    {
        $wars = [];
        $perPage = max(1, min(100, (int) config('raids.history_page_size', 50)));
        $pages = max(1, min(10, (int) config('raids.history_pages', 3)));
        $complete = false;
        for ($page = 1; $page <= $pages; $page++) {
            $query = (new GraphQLQueryBuilder)->setRootField('wars')->addArgument([
                'nation_id' => $ids, 'active' => false, 'first' => $perPage, 'page' => $page,
                'after' => now()->utc()->subDays((int) config('raids.history_days', 30))->toDateTimeString(),
                'orderBy' => [['column' => GraphQLQueryBuilder::literal('DATE'), 'order' => GraphQLQueryBuilder::literal('DESC')]],
            ])->addNestedField('data', function (GraphQLQueryBuilder $builder): void {
                $builder->addFields(['id', 'date', 'end_date', 'att_id', 'def_id', 'war_type', 'winner_id', 'turns_left', 'att_resistance', 'def_resistance', 'att_fortify', 'def_fortify', 'att_alliance_id', 'def_alliance_id'])
                    ->addNestedField('attacks', fn (GraphQLQueryBuilder $attack) => $attack->addFields([
                        'id', 'date', 'att_id', 'def_id', 'type', 'victor', 'money_stolen', 'money_looted', 'loot_info',
                        'coal_looted', 'oil_looted', 'uranium_looted', 'iron_looted', 'bauxite_looted', 'lead_looted',
                        'gasoline_looted', 'munitions_looted', 'steel_looted', 'aluminum_looted', 'food_looted',
                    ]));
            });
            $rows = (array) $this->queries->sendQuery($query, handlePagination: false);
            foreach ($rows as $raw) {
                $war = json_decode(json_encode($raw, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                if (empty($war['id']) || ! isset($war['att_id'], $war['def_id'])) {
                    continue;
                }
                foreach ($war['attacks'] ?? [] as $attack) {
                    if (empty($attack['id']) || empty($attack['date'])) {
                        continue;
                    }
                    $attack['war_id'] = (int) $war['id'];
                    $attack['war_type'] = $war['war_type'] ?? 'RAID';
                    $attack['original_attacker_id'] = (int) $war['att_id'];
                    $attack['original_defender_id'] = (int) $war['def_id'];
                    $attack['att_alliance_id'] = (int) ($war['att_alliance_id'] ?? 0);
                    $attack['def_alliance_id'] = (int) ($war['def_alliance_id'] ?? 0);
                    $observation = RaidAttackObservation::query()->firstOrNew(['id' => (int) $attack['id']]);
                    $observation->fill([
                        'war_id' => (int) $war['id'], 'att_id' => (int) ($attack['att_id'] ?? $war['att_id']),
                        'def_id' => (int) ($attack['def_id'] ?? $war['def_id']),
                        'occurred_at' => CarbonImmutable::parse($attack['date']), 'payload' => $attack,
                    ]);
                    if (! $observation->exists || $observation->isDirty()) {
                        $observation->observed_at = now();
                        $observation->save();
                    }
                }
                unset($war['attacks']);
                $wars[] = $war;
            }
            if (count($rows) < $perPage) {
                $complete = true;
                break;
            }
        }
        Cache::put('raid-history-complete:'.implode(',', $ids), $complete, 3600);

        return $wars;
    }
}

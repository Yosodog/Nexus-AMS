<?php

namespace App\Services\Raids;

use App\DataTransferObjects\MarketPriceSet;
use App\Models\City;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use App\Models\RaidLootEvent;
use App\Models\RaidTargetProfile;
use App\Services\Economy\EconomyCalculator;
use App\Services\Economy\EconomyRules;
use App\Services\Economy\MarketValuationService;
use App\Services\RaidStockpileEstimator;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds raid target profiles for a batch of nations with a fixed number of queries: one
 * insert for new profiles and one bulk update for existing profiles that changed or have a
 * settled dirty mark to clear.
 */
final class RaidTargetProfileBuilder
{
    private const RETENTION_EVENT_LIMIT = 5;

    private const RETENTION_MIN_GAP_HOURS = 12;

    /**
     * Upper bound on placeholders in one bulk profile UPDATE, below the SQLite and MySQL limits.
     */
    private const MAX_UPDATE_BINDINGS = 20_000;

    public function __construct(
        private EconomyCalculator $economy,
        private MarketValuationService $valuation,
        private RaidStockpileEstimator $estimator,
    ) {}

    /**
     * Rebuild the profiles of the given nations and write only what changed.
     *
     * Existing profiles are read before nation data, so the dirty mark seen here is never
     * newer than the data the profile is built from. The mark is cleared only when it has
     * settled (see RaidProfileDirtyMarker::settledCutoff()); a mark placed while this build
     * runs is always newer than that cutoff, so it keeps the profile dirty.
     *
     * @param  list<int>  $nationIds
     * @return int profiles rebuilt
     */
    public function build(array $nationIds): int
    {
        $nationIds = array_values(array_unique(array_filter(array_map('intval', $nationIds), fn (int $id): bool => $id > 0)));

        if ($nationIds === []) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $settled = RaidProfileDirtyMarker::settledCutoff($now);
        $existing = RaidTargetProfile::query()->whereIn('nation_id', $nationIds)->get()->keyBy('nation_id');
        $nations = Nation::query()
            ->with(['military', 'cities', 'accountProfile:nation_id,last_active'])
            ->whereIn('id', $nationIds)
            ->get()
            ->keyBy('id');
        $missing = array_values(array_diff($nationIds, $nations->keys()->map(fn (mixed $id): int => (int) $id)->all()));

        if ($missing !== []) {
            RaidTargetProfile::query()->whereIn('nation_id', $missing)->delete();
        }

        if ($nations->isEmpty()) {
            return 0;
        }

        $radiation = RadiationSnapshot::query()->latest('snapshot_at')->first();
        $prices = $this->prices();
        $victories = RaidLootEvent::query()
            ->where('kind', RaidLootEvent::KIND_VICTORY)
            ->whereIn('loser_nation_id', $nations->keys()->all())
            ->where('occurred_at', '>=', $now->subDays((int) config('raids.loot_event_retention_days')))
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('loser_nation_id');

        $rows = $nations->map(fn (Nation $nation): array => $this->row(
            $nation,
            $existing->get($nation->id),
            $victories->get($nation->id, collect()),
            $radiation,
            $prices,
            $now,
        ));

        $this->write($rows, $existing, $settled, $now);

        return $rows->count();
    }

    /**
     * Insert new profiles and update existing ones with only their changed columns.
     *
     * An existing profile is written only when a profile column changed or its settled
     * dirty mark has to be cleared. The bookkeeping columns ride along with that write.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  keyed by nation id
     * @param  Collection<int, RaidTargetProfile>  $existing  keyed by nation id
     */
    private function write(Collection $rows, Collection $existing, CarbonImmutable $settled, CarbonImmutable $now): void
    {
        $inserts = [];
        $updates = [];

        foreach ($rows as $nationId => $row) {
            $profile = $existing->get($nationId);

            if ($profile === null) {
                $inserts[] = $row + ['projected_at' => $now, 'dirty_at' => null, 'computed_at' => $now, 'created_at' => $now, 'updated_at' => $now];

                continue;
            }

            $changes = $this->changedColumns($profile, $row);
            $hasSettledMark = $profile->dirty_at !== null && $profile->dirty_at->lessThanOrEqualTo($settled);

            if ($changes === [] && ! $hasSettledMark) {
                continue;
            }

            if (array_key_exists('projected_value', $changes)) {
                $changes['projected_at'] = $now;
            }

            $updates[(int) $nationId] = $changes;
        }

        if ($inserts !== []) {
            RaidTargetProfile::query()->insertOrIgnore($inserts);
        }

        $batch = [];
        $bindings = 0;

        foreach ($updates as $nationId => $changes) {
            if ($batch !== [] && $bindings + 2 * count($changes) + 1 > self::MAX_UPDATE_BINDINGS) {
                $this->updateProfiles($batch, $settled, $now);
                $batch = [];
                $bindings = 0;
            }

            $batch[$nationId] = $changes;
            $bindings += 2 * count($changes) + 1;
        }

        if ($batch !== []) {
            $this->updateProfiles($batch, $settled, $now);
        }
    }

    /**
     * Profile columns whose rebuilt value differs from the stored one, compared through the
     * model casts so a value that only differs in type or precision does not count.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function changedColumns(RaidTargetProfile $profile, array $row): array
    {
        $profile->forceFill(Arr::except($row, ['nation_id']));

        return array_intersect_key($row, $profile->getDirty());
    }

    /**
     * Update a batch of profiles with one statement. Each changed column gets a CASE that
     * groups the profiles sharing a value, so a value common to the batch (a sliding
     * production-only baseline, projected_at) costs one binding.
     *
     * Settled marks are cleared with `dirty_at <= $settled`, which matches comparing with the
     * value read before the build: once the build has started, every dirty_at the marker writes
     * is a later second than $settled, and a mark the marker skips is already newer than
     * $settled. A stored value at or before $settled is therefore the one the build read.
     *
     * @param  array<int, array<string, mixed>>  $updates  changed columns keyed by nation id
     */
    private function updateProfiles(array $updates, CarbonImmutable $settled, CarbonImmutable $now): void
    {
        $connection = (new RaidTargetProfile)->getConnection();
        $grammar = $connection->getQueryGrammar();
        $nationIdColumn = $grammar->wrap('nation_id');
        $assignments = [];
        $bindings = [];
        $columns = collect($updates)->flatMap(fn (array $changes): array => array_keys($changes))->unique()->sort()->values();

        foreach ($columns as $column) {
            $groups = [];

            foreach ($updates as $nationId => $changes) {
                if (array_key_exists($column, $changes)) {
                    $value = $changes[$column];
                    $key = $value instanceof DateTimeInterface ? 'date:'.$value->getTimestamp() : get_debug_type($value).':'.var_export($value, true);
                    $groups[$key] ??= ['value' => $value, 'ids' => []];
                    $groups[$key]['ids'][] = $nationId;
                }
            }

            $wrapped = $grammar->wrap($column);
            $cases = '';

            foreach ($groups as $group) {
                $cases .= " WHEN {$nationIdColumn} IN (".implode(', ', array_fill(0, count($group['ids']), '?')).') THEN ?';
                array_push($bindings, ...$group['ids'], ...[$group['value']]);
            }

            $assignments[] = "{$wrapped} = CASE{$cases} ELSE {$wrapped} END";
        }

        $dirtyAt = $grammar->wrap('dirty_at');
        $assignments[] = $grammar->wrap('computed_at').' = ?';
        $assignments[] = $grammar->wrap('updated_at').' = ?';
        $assignments[] = "{$dirtyAt} = CASE WHEN {$dirtyAt} <= ? THEN NULL ELSE {$dirtyAt} END";
        array_push($bindings, $now, $now, $settled);
        $ids = array_keys($updates);

        $connection->update(
            'UPDATE '.$grammar->wrapTable((new RaidTargetProfile)->getTable()).' SET '.implode(', ', $assignments)
                ." WHERE {$nationIdColumn} IN (".implode(', ', array_fill(0, count($ids), '?')).')',
            [...$bindings, ...$ids],
        );
    }

    /**
     * @param  Collection<int, RaidLootEvent>  $victories
     * @return array<string, mixed>
     */
    private function row(
        Nation $nation,
        ?RaidTargetProfile $existing,
        Collection $victories,
        ?RadiationSnapshot $radiation,
        ?MarketPriceSet $prices,
        CarbonImmutable $now,
    ): array {
        $cities = $nation->cities;
        $military = $nation->military;
        $units = collect(['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'])
            ->mapWithKeys(fn (string $unit): array => [$unit => max(0, (int) ($military?->{$unit} ?? 0))])
            ->all();
        $economy = $this->economy($nation, $existing, $units, $radiation, $prices, $now);
        $baseline = $this->baseline($nation, $victories, $now);
        $retention = $this->retention($victories, $economy['daily_net'], $prices);

        $attributes = [
            'nation_id' => (int) $nation->id,
            'nation_name' => mb_substr((string) $nation->nation_name, 0, 64),
            'leader_name' => mb_substr((string) $nation->leader_name, 0, 64),
            'alliance_id' => max(0, (int) $nation->alliance_id),
            'alliance_position' => $nation->alliance_position === null ? null : (string) $nation->alliance_position,
            'score' => round((float) $nation->score, 2),
            'num_cities' => (int) $nation->num_cities,
            'color' => mb_substr((string) $nation->color, 0, 16),
            'beige_turns' => max(0, (int) $nation->beige_turns),
            'vacation_mode_turns' => max(0, (int) $nation->vacation_mode_turns),
            'last_active' => $nation->accountProfile?->last_active === null
                ? null
                : CarbonImmutable::instance($nation->accountProfile->last_active),
            'war_policy' => $nation->war_policy === null ? null : (string) $nation->war_policy,
            ...$units,
            'highest_city_population' => $economy['highest_city_population'],
            'highest_city_infra' => round((float) $cities->max('infrastructure'), 2),
            'avg_infra' => round((float) $cities->avg('infrastructure'), 2),
            'defensive_wars' => min(255, max(0, (int) $nation->defensive_wars_count)),
            'offensive_wars' => min(255, max(0, (int) $nation->offensive_wars_count)),
            'baseline_kind' => $baseline['kind'],
            'baseline_at' => $baseline['at'],
            'baseline_attack_id' => $baseline['attack_id'],
        ];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $attributes['baseline_'.$resource] = round($baseline['resources'][$resource], 2);
        }

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $attributes['daily_net_'.$resource] = round($economy['daily_net'][$resource], 2);
        }

        $attributes += [
            'economy_hash' => $economy['hash'],
            'economy_computed_at' => $economy['computed_at'],
            'retention_observed' => $retention['observed'],
            'retention_samples' => $retention['samples'],
        ];

        $projection = $this->estimator->project(new RaidTargetProfile($attributes), $now)['resources'];

        return $attributes + [
            'projected_value' => $prices === null
                ? (float) ($existing?->projected_value ?? 0.0)
                : round(RaidResources::liquidationValue($projection, $prices), 2),
        ];
    }

    /**
     * @param  array<string, int>  $units
     * @return array{daily_net: array<string, float>, hash: string, computed_at: CarbonImmutable|null, highest_city_population: int}
     */
    private function economy(
        Nation $nation,
        ?RaidTargetProfile $existing,
        array $units,
        ?RadiationSnapshot $radiation,
        ?MarketPriceSet $prices,
        CarbonImmutable $now,
    ): array {
        $hash = $this->economyHash($nation, $units);
        $previous = [
            'daily_net' => $existing?->dailyNet() ?? RaidResources::empty(),
            'hash' => (string) ($existing?->economy_hash ?? ''),
            'computed_at' => $existing?->economy_computed_at,
            'highest_city_population' => (int) ($existing?->highest_city_population ?? 0),
        ];
        $fresh = $existing?->economy_computed_at !== null
            && $existing->economy_computed_at->greaterThan($now->subHours((int) config('raids.profiles.economy_max_age_hours')));

        if (($existing?->economy_hash === $hash && $fresh) || $prices === null) {
            return $previous;
        }

        try {
            $result = $this->economy->calculateNation($nation, $radiation, $prices, $now);
            $projects = $nation->projects;
            $hasProject = fn (string $project): bool => (bool) ($projects[$project] ?? false);
            $gameDate = $radiation?->game_date === null ? null : CarbonImmutable::instance($radiation->game_date);

            return [
                'daily_net' => array_replace(RaidResources::empty(), array_map(
                    'floatval',
                    array_intersect_key((array) ($result['resource_profit_per_day'] ?? []), RaidResources::empty()),
                )),
                'hash' => $hash,
                'computed_at' => $now,
                'highest_city_population' => (int) $nation->cities
                    ->map(fn (City $city): int => $this->economy->population($city, $hasProject, $gameDate, $now))
                    ->max(),
            ];
        } catch (Throwable $exception) {
            Log::warning('Raid target profile economy could not be computed.', [
                'nation_id' => (int) $nation->id,
                'exception_class' => $exception::class,
            ]);

            return $previous;
        }
    }

    /**
     * @param  array<string, int>  $units
     */
    private function economyHash(Nation $nation, array $units): string
    {
        $cities = $nation->cities
            ->sortBy('id')
            ->map(fn (City $city): array => collect($city->getAttributes())
                ->except(['id', 'created_at', 'updated_at', 'deleted_at', 'nation_id'])
                ->sortKeys()
                ->all())
            ->values()
            ->all();

        return sha1((string) json_encode([
            $cities,
            $nation->project_bits,
            $nation->domestic_policy,
            $nation->continent,
            $nation->color,
            $nation->treasure_income_modifier,
            $nation->color_turn_bonus,
            $units,
        ]));
    }

    /**
     * @param  Collection<int, RaidLootEvent>  $victories
     * @return array{kind: string, at: CarbonImmutable, attack_id: int|null, resources: array<string, float>}
     */
    private function baseline(Nation $nation, Collection $victories, CarbonImmutable $now): array
    {
        $latest = $victories->filter(fn (RaidLootEvent $event): bool => (float) $event->loot_fraction > 0)->last();

        if ($latest !== null) {
            return [
                'kind' => RaidTargetProfile::BASELINE_LOOT,
                'at' => $latest->occurred_at,
                'attack_id' => (int) $latest->id,
                'resources' => $this->afterLoot($latest),
            ];
        }

        $productionStart = $now->subDays((int) config('raids.estimator.production_only_days'));
        $createdAt = $nation->created_at === null ? null : CarbonImmutable::instance($nation->created_at);

        return [
            'kind' => RaidTargetProfile::BASELINE_PRODUCTION_ONLY,
            'at' => $createdAt !== null && $createdAt->greaterThan($productionStart) ? $createdAt : $productionStart,
            'attack_id' => null,
            'resources' => RaidResources::empty(),
        ];
    }

    /**
     * Measure how much of its full production a nation kept between consecutive victories.
     *
     * @param  Collection<int, RaidLootEvent>  $victories
     * @param  array<string, float>  $dailyNet
     * @return array{observed: float|null, samples: int}
     */
    private function retention(Collection $victories, array $dailyNet, ?MarketPriceSet $prices): array
    {
        if ($prices === null) {
            return ['observed' => null, 'samples' => 0];
        }

        $events = $victories
            ->filter(fn (RaidLootEvent $event): bool => (float) $event->loot_fraction > 0)
            ->take(-self::RETENTION_EVENT_LIMIT)
            ->values();
        $ratios = [];

        foreach ($events as $index => $first) {
            $second = $events->get($index + 1);

            if ($second === null) {
                break;
            }

            if ($second->occurred_at->getTimestamp() - $first->occurred_at->getTimestamp() < self::RETENTION_MIN_GAP_HOURS * 3600) {
                continue;
            }

            $afterFirst = $this->afterLoot($first);
            $afterValue = RaidResources::liquidationValue($afterFirst, $prices);
            $fullRetention = RaidResources::liquidationValue(
                $this->estimator->projectFrom($afterFirst, $dailyNet, $first->occurred_at, $second->occurred_at, 1.0)['resources'],
                $prices,
            );
            $revealed = RaidResources::liquidationValue(
                array_map(fn (float $amount): float => $amount / (float) $second->loot_fraction, $second->lootedResources()),
                $prices,
            );

            if ($fullRetention - $afterValue > 0) {
                $ratios[] = max(0.0, min(1.0, ($revealed - $afterValue) / ($fullRetention - $afterValue)));
            }
        }

        return $ratios === []
            ? ['observed' => null, 'samples' => 0]
            : ['observed' => round(array_sum($ratios) / count($ratios), 4), 'samples' => count($ratios)];
    }

    /**
     * Stockpile left with the loser right after a victory.
     *
     * @return array<string, float>
     */
    private function afterLoot(RaidLootEvent $event): array
    {
        $fraction = (float) $event->loot_fraction;

        return array_map(
            fn (float $looted): float => max(0.0, $looted / $fraction - $looted),
            $event->lootedResources(),
        );
    }

    private function prices(): ?MarketPriceSet
    {
        try {
            return $this->valuation->current();
        } catch (Throwable $exception) {
            Log::warning('Raid target profiles are building without market prices.', [
                'exception_class' => $exception::class,
            ]);

            return null;
        }
    }
}

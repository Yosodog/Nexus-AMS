<?php

namespace App\Services\Economy;

use App\Models\Nation;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\World\WorldWriteGuard;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EconomyContextService
{
    private const READ_CHUNK_SIZE = 1000;

    private const WRITE_CHUNK_SIZE = 100;

    /**
     * Half of the smallest step the decimal(8, 6) column can store.
     */
    private const MODIFIER_TOLERANCE = 0.0000005;

    private const CONTEXT_COLUMNS = [
        'id',
        'alliance_id',
        'alliance_position',
        'color',
        'treasure_income_modifier',
        'color_turn_bonus',
        'economy_context_synced_at',
    ];

    public function __construct(private readonly WorldWriteGuard $worldWriteGuard) {}

    /**
     * Recompute every active nation's treasure and color context and persist the rows that changed.
     *
     * Nations are read without locks in bounded chunks. Only nations whose stored context differs,
     * or whose stamp is due for renewal, are locked and written, each chunk in its own short
     * transaction. Upstream data is fully validated before anything is written, so a bad response
     * leaves every nation untouched; a failure while writing keeps the chunks already committed,
     * each of which is correct on its own.
     *
     * @return int The number of active nations whose context is current after the refresh.
     */
    public function refresh(): int
    {
        $this->worldWriteGuard->assertCanWrite(Nation::class);

        $treasures = $this->queryTreasures();
        $colors = $this->queryRootList('colors', ['color', 'turn_bonus']);

        if ($treasures === [] || $colors === []) {
            throw new RuntimeException('Economy context response was empty.');
        }

        $colorBonuses = collect($colors)->mapWithKeys(function (object $color): array {
            $name = strtolower(trim((string) ($color->color ?? '')));
            $turnBonus = (float) ($color->turn_bonus ?? -1);

            if (
                $name === ''
                || ! property_exists($color, 'turn_bonus')
                || ! is_numeric($color->turn_bonus)
                || ! is_finite($turnBonus)
                || $turnBonus < 0
                || floor($turnBonus) !== $turnBonus
                || $turnBonus > 2_147_483_647
            ) {
                throw new RuntimeException('Economy context response contained an invalid color bonus.');
            }

            return [$name => (int) $color->turn_bonus];
        });

        if ($colorBonuses->count() !== count($colors)) {
            throw new RuntimeException('Economy context response contained duplicate colors.');
        }

        $treasureNames = [];

        foreach ($treasures as $treasure) {
            $ownerNation = (array) ($treasure->nation ?? []);
            $name = trim((string) ($treasure->name ?? ''));
            $bonus = (float) ($treasure->bonus ?? -1);

            if (
                $name === ''
                || isset($treasureNames[strtolower($name)])
                || ! property_exists($treasure, 'nation_id')
                || ! is_numeric($treasure->nation_id)
                || (int) $treasure->nation_id <= 0
                || ! property_exists($treasure, 'bonus')
                || ! is_numeric($treasure->bonus)
                || ! is_finite($bonus)
                || $bonus < 0
                || floor($bonus) !== $bonus
                || ! array_key_exists('alliance_id', $ownerNation)
                || ($ownerNation['alliance_id'] !== null && ! is_numeric($ownerNation['alliance_id']))
                || ! array_key_exists('id', $ownerNation)
                || ! is_numeric($ownerNation['id'])
                || (int) $ownerNation['id'] !== (int) $treasure->nation_id
            ) {
                throw new RuntimeException('Economy context response contained an invalid treasure.');
            }

            $treasureNames[strtolower($name)] = true;
        }

        $treasureOwners = collect($treasures)
            ->groupBy(fn (object $treasure): int => (int) $treasure->nation_id);
        $allianceTreasureCounts = collect($treasures)
            ->filter(fn (object $treasure): bool => (int) data_get($treasure, 'nation.alliance_id', 0) > 0)
            ->countBy(fn (object $treasure): int => (int) data_get($treasure, 'nation.alliance_id'));
        $timestamp = now()->toDateTimeString();
        $restampBefore = now()->subHours(EconomyRules::ECONOMY_CONTEXT_RESTAMP_AFTER_HOURS);

        // Bound the run to the nations that exist when it starts. Nations created while it runs
        // keep their empty context until the next refresh instead of joining a half-finished pass.
        $maxNationId = Nation::query()->max('id');

        if ($maxNationId === null) {
            throw new RuntimeException('No active nations were available for economy context synchronization.');
        }

        $this->assertActiveNationColorsAreCovered((int) $maxNationId, $colorBonuses);

        $processedCount = 0;

        Nation::query()
            ->select(self::CONTEXT_COLUMNS)
            ->where('id', '<=', $maxNationId)
            ->chunkById(self::READ_CHUNK_SIZE, function (EloquentCollection $nations) use (
                $treasureOwners,
                $allianceTreasureCounts,
                $colorBonuses,
                $timestamp,
                $restampBefore,
                &$processedCount
            ): void {
                $staleNationIds = $nations
                    ->filter(fn (Nation $nation): bool => $this->needsWrite(
                        $nation,
                        $this->contextFor($nation, $treasureOwners, $allianceTreasureCounts, $colorBonuses),
                        $restampBefore
                    ))
                    ->modelKeys();

                $processedCount += $nations->count() - count($staleNationIds);

                foreach (array_chunk($staleNationIds, self::WRITE_CHUNK_SIZE) as $nationIds) {
                    $processedCount += $this->writeChunk(
                        $nationIds,
                        $treasureOwners,
                        $allianceTreasureCounts,
                        $colorBonuses,
                        $timestamp,
                        $restampBefore
                    );
                }
            });

        return $processedCount;
    }

    /**
     * Lock, re-read and write one bounded set of nations whose stored context is out of date.
     *
     * Only the rows that are about to change are locked, and only for the length of this short
     * transaction. Re-reading the alliance and color under the lock means a concurrent nation sync
     * that changed them either commits first (and the context is derived from the new values) or
     * waits for this commit (and then clears the context itself), so the refresh never stamps
     * context derived from source data that has since changed.
     *
     * @param  list<int>  $nationIds
     * @param  Collection<int, Collection<int, object>>  $treasureOwners
     * @param  Collection<int, int>  $allianceTreasureCounts
     * @param  Collection<string, int>  $colorBonuses
     * @return int The number of nations whose context is current after the write.
     */
    private function writeChunk(
        array $nationIds,
        Collection $treasureOwners,
        Collection $allianceTreasureCounts,
        Collection $colorBonuses,
        string $timestamp,
        CarbonInterface $restampBefore
    ): int {
        return DB::transaction(function () use (
            $nationIds,
            $treasureOwners,
            $allianceTreasureCounts,
            $colorBonuses,
            $timestamp,
            $restampBefore
        ): int {
            $nations = Nation::query()
                ->select(self::CONTEXT_COLUMNS)
                ->whereKey($nationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $currentCount = 0;
            $writes = [];

            foreach ($nations as $nation) {
                $context = $this->contextFor($nation, $treasureOwners, $allianceTreasureCounts, $colorBonuses);

                if ($context === null) {
                    continue;
                }

                $currentCount++;

                if (! $this->needsWrite($nation, $context, $restampBefore)) {
                    continue;
                }

                $key = $context['treasure_income_modifier'].'|'.$context['color_turn_bonus'];
                $writes[$key]['context'] = $context;
                $writes[$key]['ids'][] = (int) $nation->id;
            }

            foreach ($writes as $write) {
                $updated = DB::table('nations')
                    ->whereIn('id', $write['ids'])
                    ->update([
                        ...$write['context'],
                        'economy_context_synced_at' => $timestamp,
                    ]);

                if ($updated !== count($write['ids'])) {
                    throw new RuntimeException(
                        'Economy context synchronization expected to update '.count($write['ids'])
                        ." nations and updated {$updated}."
                    );
                }
            }

            return $currentCount;
        }, 3);
    }

    /**
     * @param  Collection<string, int>  $colorBonuses
     */
    private function assertActiveNationColorsAreCovered(int $maxNationId, Collection $colorBonuses): void
    {
        if (
            Nation::query()
                ->where('id', '<=', $maxNationId)
                ->where(function ($query): void {
                    $query->whereNull('color')->orWhere('color', '');
                })
                ->exists()
        ) {
            throw new RuntimeException('One or more active nations are missing a color.');
        }

        $missingColors = Nation::query()
            ->where('id', '<=', $maxNationId)
            ->distinct()
            ->pluck('color')
            ->filter()
            ->map(fn (mixed $color): string => strtolower((string) $color))
            ->unique()
            ->diff($colorBonuses->keys());

        if ($missingColors->isNotEmpty()) {
            throw new RuntimeException('Economy context response omitted active nation colors.');
        }
    }

    /**
     * Derive a nation's context, or null when its color changed mid-run to one the validated
     * response does not cover. That nation is left alone and picked up by the next refresh.
     *
     * @param  Collection<int, Collection<int, object>>  $treasureOwners
     * @param  Collection<int, int>  $allianceTreasureCounts
     * @param  Collection<string, int>  $colorBonuses
     * @return array{treasure_income_modifier: float, color_turn_bonus: int}|null
     */
    private function contextFor(
        Nation $nation,
        Collection $treasureOwners,
        Collection $allianceTreasureCounts,
        Collection $colorBonuses
    ): ?array {
        $color = strtolower((string) $nation->color);

        if ($color === '' || ! $colorBonuses->has($color)) {
            return null;
        }

        $ownBonus = $treasureOwners->get((int) $nation->id, collect())
            ->sum(fn (object $treasure): int => (int) ($treasure->bonus ?? 0));
        $isAllianceMember = $nation->alliance_id !== null
            && ! in_array($nation->alliance_position, ['NOALLIANCE', 'APPLICANT'], true);
        $allianceTreasureCount = $isAllianceMember
            ? (int) $allianceTreasureCounts->get((int) $nation->alliance_id, 0)
            : 0;

        return [
            'treasure_income_modifier' => round(((sqrt($allianceTreasureCount * 4)) + $ownBonus) * 0.01, 6),
            'color_turn_bonus' => (int) $colorBonuses->get($color),
        ];
    }

    /**
     * A nation needs a write when its stored context differs from the derived one, or when the
     * values still match but the stamp is old enough that it must be renewed to stay fresh.
     *
     * @param  array{treasure_income_modifier: float, color_turn_bonus: int}|null  $context
     */
    private function needsWrite(Nation $nation, ?array $context, CarbonInterface $restampBefore): bool
    {
        if ($context === null) {
            return true;
        }

        return $nation->treasure_income_modifier === null
            || $nation->color_turn_bonus === null
            || $nation->economy_context_synced_at === null
            || abs((float) $nation->treasure_income_modifier - $context['treasure_income_modifier']) > self::MODIFIER_TOLERANCE
            || (int) $nation->color_turn_bonus !== $context['color_turn_bonus']
            || $nation->economy_context_synced_at->lte($restampBefore);
    }

    /**
     * @param  list<string>  $fields
     * @return list<object>
     */
    private function queryRootList(string $root, array $fields): array
    {
        $query = (new GraphQLQueryBuilder)
            ->setRootField($root)
            ->addFields($fields);

        return array_map(
            static fn (mixed $item): object => (object) $item,
            array_values((array) (new QueryService)->sendQuery($query))
        );
    }

    /**
     * @return list<object>
     */
    private function queryTreasures(): array
    {
        $query = (new GraphQLQueryBuilder)
            ->setRootField('treasures')
            ->addFields(['name', 'bonus', 'nation_id'])
            ->addNestedField('nation', function (GraphQLQueryBuilder $nationBuilder): void {
                $nationBuilder->addFields(['id', 'alliance_id']);
            });

        return array_map(
            static fn (mixed $item): object => (object) $item,
            array_values((array) (new QueryService)->sendQuery($query))
        );
    }
}

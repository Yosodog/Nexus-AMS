<?php

namespace App\Services\Economy;

use App\Models\Nation;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use App\Services\World\WorldWriteGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EconomyContextService
{
    private const READ_CHUNK_SIZE = 1000;

    private const UPSERT_CHUNK_SIZE = 100;

    public function __construct(private readonly WorldWriteGuard $worldWriteGuard) {}

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
        $nationCount = Nation::query()->count();

        if ($nationCount === 0) {
            throw new RuntimeException('No active nations were available for economy context synchronization.');
        }

        if (Nation::query()->whereNull('color')->orWhere('color', '')->exists()) {
            throw new RuntimeException('One or more active nations are missing a color.');
        }

        $missingColors = Nation::query()->distinct()->pluck('color')
            ->filter()
            ->map(fn (mixed $color): string => strtolower((string) $color))
            ->unique()
            ->diff($colorBonuses->keys());

        if ($missingColors->isNotEmpty()) {
            throw new RuntimeException('Economy context response omitted active nation colors.');
        }
        $allianceTreasureCounts = collect($treasures)
            ->filter(fn (object $treasure): bool => (int) data_get($treasure, 'nation.alliance_id', 0) > 0)
            ->countBy(fn (object $treasure): int => (int) data_get($treasure, 'nation.alliance_id'));
        $timestamp = now()->toDateTimeString();

        DB::transaction(function () use (
            $treasureOwners,
            $allianceTreasureCounts,
            $colorBonuses,
            $timestamp,
            $nationCount
        ): void {
            $processedCount = 0;

            Nation::query()->lockForUpdate()->chunkById(self::READ_CHUNK_SIZE, function ($nations) use (
                $treasureOwners,
                $allianceTreasureCounts,
                $colorBonuses,
                $timestamp,
                &$processedCount
            ): void {
                $rows = $nations->map(function (Nation $nation) use (
                    $treasureOwners,
                    $allianceTreasureCounts,
                    $colorBonuses,
                    $timestamp
                ): array {
                    $ownBonus = $treasureOwners->get((int) $nation->id, collect())
                        ->sum(fn (object $treasure): int => (int) ($treasure->bonus ?? 0));
                    $isAllianceMember = $nation->alliance_id !== null
                        && ! in_array($nation->alliance_position, ['NOALLIANCE', 'APPLICANT'], true);
                    $allianceTreasureCount = $isAllianceMember
                        ? (int) $allianceTreasureCounts->get((int) $nation->alliance_id, 0)
                        : 0;

                    return [
                        ...$nation->getAttributes(),
                        'treasure_income_modifier' => round(
                            ((sqrt($allianceTreasureCount * 4)) + $ownBonus) * 0.01,
                            6
                        ),
                        'color_turn_bonus' => (int) $colorBonuses->get(
                            strtolower((string) $nation->color),
                            0
                        ),
                        'economy_context_synced_at' => $timestamp,
                    ];
                });

                $rows->chunk(self::UPSERT_CHUNK_SIZE)->each(function ($chunk): void {
                    DB::table('nations')->upsert(
                        $chunk->all(),
                        ['id'],
                        ['treasure_income_modifier', 'color_turn_bonus', 'economy_context_synced_at']
                    );
                });

                $processedCount += $rows->count();
            });

            if ($processedCount !== $nationCount) {
                throw new RuntimeException(
                    "Economy context synchronization expected {$nationCount} nations and processed {$processedCount}."
                );
            }

            $verifiedCount = Nation::query()
                ->where('economy_context_synced_at', $timestamp)
                ->count();

            if ($verifiedCount !== $nationCount) {
                throw new RuntimeException(
                    "Economy context verification expected {$nationCount} nations and found {$verifiedCount}."
                );
            }
        }, 3);

        return $nationCount;
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

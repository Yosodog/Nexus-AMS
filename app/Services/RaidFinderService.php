<?php

namespace App\Services;

use App\GraphQL\Models\Nation as NationModel;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\NoRaidList;
use App\Models\Setting;
use App\Models\Treaty;
use Illuminate\Support\Collection;

class RaidFinderService
{
    /**
     * @param TradePriceService $priceService
     * @param LootCalculatorService $lootCalculator
     */
    public function __construct(
        protected TradePriceService $priceService,
        protected LootCalculatorService $lootCalculator,
    ) {}

    /**
     * @param int $nationId
     * @return Collection
     */
    public function findTargets(int $nationId): Collection
    {
        $ownNation = Nation::findOrFail($nationId);

        if ($ownNation->alliance_id !== (int)env("PW_ALLIANCE_ID")) {
            abort(403, 'Nation does not belong to our alliance.');
        }

        $noRaidAlliances = NoRaidList::pluck('alliance_id')->toArray();
        $priceSnapshot = $this->priceService->get24hAverage();

        $minScore = $ownNation->score * 0.75;
        $maxScore = $ownNation->score * 1.75;

        // Load all raidable nations
        $nations = $this->queryRaidableNations($minScore, $maxScore);

        $targets = collect();

        foreach ($nations as $nation) {
            // Filter out invalid targets
            if (in_array($nation->alliance_id, $noRaidAlliances, true)) {
                continue;
            }

            $defensiveWars = 0;
            $lootTotal = 0;
            $validWarCount = 0;
            $lastBeigeValue = null;

            foreach ($nation->wars as $war) {
                if ($defensiveWars >= 3) break;
                if ($war->def_id !== $nation->id) continue;

                if ($war->turns_left > 0) {
                    $defensiveWars++;
                    continue;
                }

                if ($war->winner_id === $nation->id) continue;

                $loot = $this->lootCalculator->calculateFromGraphQLWar($war);

                if ($validWarCount === 0) {
                    $lastBeigeValue = $loot;
                }

                $lootTotal += $loot;
                $validWarCount++;

                if ($validWarCount > 10) break;
            }

            if ($defensiveWars >= 3 || $validWarCount === 0) {
                continue;
            }

            $averageLoot = (int) round($lootTotal / $validWarCount);

            $targets->push(collect([
                'nation' => $nation,
                'value' => $averageLoot,
                'defensive_wars' => $defensiveWars,
                'last_beige' => $lastBeigeValue,
            ]));
        }

        return $targets->sortByDesc('value')->values();
    }

    /**
     * @param float $minScore
     * @param float $maxScore
     * @return Collection
     */
    private function queryRaidableNations(float $minScore, float $maxScore): Collection
    {
        $raidableAlliances = $this->getRaidableAllianceIDs();

        $query = (new GraphQLQueryBuilder())
            ->setRootField('nations')
            ->addArgument('min_score', $minScore)
            ->addArgument('max_score', $maxScore)
            ->addArgument('vmode', false)
            ->addArgument('first', 500)
            ->addArgument('color', [
                "aqua", "black", "blue", "brown", "green", "lime", "maroon",
                "olive", "orange", "pink", "purple", "red", "white", "yellow", "gray"
            ])
            ->addArgument('alliance_id', $raidableAlliances)
            ->addNestedField('paginatorInfo', fn($b) =>
            $b->addFields(['hasMorePages', 'lastPage', 'currentPage'])
            )
            ->addNestedField('data', function (GraphQLQueryBuilder $b) {
                $b->addFields([
                    'id',
                    'leader_name',
                    'alliance_id',
                    'alliance_position',
                    'vmode',
                    'last_active',
                    'score',
                    'num_cities',
                    'war_policy',
                ])
                    ->addNestedField('alliance', fn($b) =>
                    $b->addFields(['id', 'name'])
                    )
                    ->addNestedField('wars', function (GraphQLQueryBuilder $b) {
                        $b->addArgument('active', false)
                            ->addArgument('orderBy', [
                                'column' => 'DATE',
                                'order' => 'DESC',
                            ])
                            ->addFields([
                                'id',
                                'date',
                                'def_id',
                                'winner_id',
                                'turns_left',
                            ])
                            ->addNestedField('attacks', fn($b) =>
                            $b->addFields([
                                'money_looted', 'money_stolen',
                                'coal_looted', 'oil_looted', 'uranium_looted',
                                'iron_looted', 'bauxite_looted', 'lead_looted',
                                'gasoline_looted', 'munitions_looted',
                                'steel_looted', 'aluminum_looted', 'food_looted',
                            ])
                            );
                    });
            })
            ->withPaginationInfo();

        try {
            $results = (new QueryService())->sendQuery($query);
        } catch (\Throwable $e) {
            abort(503, 'PW API error while querying nations: ' . $e->getMessage());
        }

        $nationModels = collect();
        foreach ($results as $json) {
            $nation = new \App\GraphQL\Models\Nation();
            $nation->buildWithJSON((object) $json);
            $nationModels->push($nation);
        }

        return $nationModels;
    }

    /**
     * @return array
     */
    private function getRaidableAllianceIDs(): array
    {
        $topN = SettingService::getTopRaidable(); // Admin configurable
        $topAlliances = Alliance::orderByDesc('score')->take($topN)->pluck('id')->toArray();
        $eligibleAlliances = Alliance::whereNotIn('id', $topAlliances)->pluck('id')->toArray();
        $noRaidList = NoRaidList::pluck('alliance_id')->toArray();
        $treaties = Treaty::all();

        $raidable = [];

        foreach ($eligibleAlliances as $aid) {
            if (in_array($aid, $noRaidList)) {
                continue;
            }

            $hasTreatyWithTop = $treaties->contains(function ($treaty) use ($aid, $topAlliances) {
                return (
                    ($treaty->alliance1_id === $aid && in_array($treaty->alliance2_id, $topAlliances)) ||
                    ($treaty->alliance2_id === $aid && in_array($treaty->alliance1_id, $topAlliances))
                );
            });

            if (! $hasTreatyWithTop) {
                $raidable[] = $aid;
            }
        }

        return array_unique($raidable);
    }
}
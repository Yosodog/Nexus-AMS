<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Models\Nation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class RaidFinderService
{
    public function __construct(
        protected TradePriceService $priceService,
        protected LootCalculatorService $lootCalculator,
        protected AllianceMembershipService $membershipService,
        protected RaidPolicyService $raidPolicy,
    ) {}

    /**
     * @return Collection<int, mixed>
     */
    public function findTargets(int $nationId): Collection
    {
        $ownNation = Nation::findOrFail($nationId);

        if (! $this->membershipService->contains($ownNation->alliance_id)) {
            abort(403, 'Nation does not belong to our alliance.');
        }

        $priceSnapshot = $this->priceService->get24hAverage();

        $minScore = $ownNation->score * 0.75;
        $maxScore = $ownNation->score * 1.75;

        // Load all raidable nations
        $nations = $this->queryRaidableNations($minScore, $maxScore);

        $targets = collect();

        foreach ($nations as $nation) {
            // Filter out invalid targets
            $defensiveWars = 0;
            $lootTotal = 0;
            $validWarCount = 0;
            $lastBeigeValue = null;

            foreach ($nation->wars as $war) {
                if ($defensiveWars >= 3) {
                    break;
                }
                if ($war->def_id !== $nation->id) {
                    continue;
                }

                if ($war->turns_left > 0) {
                    $defensiveWars++;

                    continue;
                }

                if ($war->winner_id === $nation->id) {
                    continue;
                }

                $loot = $this->lootCalculator->calculateFromGraphQLWar($war);

                if ($validWarCount === 0) {
                    $lastBeigeValue = $loot;
                }

                $lootTotal += $loot;
                $validWarCount++;

                if ($validWarCount > 10) {
                    break;
                }
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
     * @return Collection<int, mixed>
     */
    private function queryRaidableNations(float $minScore, float $maxScore): Collection
    {
        $raidableAlliances = $this->raidPolicy->raidableAllianceIds();

        $query = (new GraphQLQueryBuilder)
            ->setRootField('nations')
            ->addArgument('min_score', $minScore)
            ->addArgument('max_score', $maxScore)
            ->addArgument('vmode', false)
            ->addArgument('first', 500)
            ->addArgument('color', [
                'aqua',
                'black',
                'blue',
                'brown',
                'green',
                'lime',
                'maroon',
                'olive',
                'orange',
                'pink',
                'purple',
                'red',
                'white',
                'yellow',
                'gray',
            ])
            ->addArgument('alliance_id', $raidableAlliances)
            ->addNestedField('paginatorInfo', fn ($b) => $b->addFields(['hasMorePages', 'lastPage', 'currentPage'])
            )
            ->addNestedField('data', function (GraphQLQueryBuilder $b) {
                $b->addFields([
                    'id',
                    'nation_name',
                    'leader_name',
                    'alliance_id',
                    'alliance_position',
                    'vmode',
                    'last_active',
                    'score',
                    'num_cities',
                    'war_policy',
                    'soldiers',
                    'tanks',
                    'aircraft',
                    'ships',
                    'spies',
                    'missiles',
                    'nukes',
                ])
                    ->addNestedField('alliance', fn ($b) => $b->addFields(SelectionSetHelper::allianceSet())
                    )
                    ->addNestedField('wars', function (GraphQLQueryBuilder $b) {
                        $b->addArgument('active', false)
                            ->addArgument('orderBy', [[
                                'column' => GraphQLQueryBuilder::literal('DATE'),
                                'order' => GraphQLQueryBuilder::literal('DESC'),
                            ]])
                            ->addFields([
                                'id',
                                'date',
                                'def_id',
                                'winner_id',
                                'turns_left',
                            ])
                            ->addNestedField('attacks', fn ($b) => $b->addFields([
                                'money_looted',
                                'money_stolen',
                                'coal_looted',
                                'oil_looted',
                                'uranium_looted',
                                'iron_looted',
                                'bauxite_looted',
                                'lead_looted',
                                'gasoline_looted',
                                'munitions_looted',
                                'steel_looted',
                                'aluminum_looted',
                                'food_looted',
                            ])
                            );
                    });
            })
            ->withPaginationInfo();

        try {
            $results = app(QueryService::class)->sendQuery($query);
        } catch (PWQueryFailedException $exception) {
            if ($exception->retryAfterSeconds !== null) {
                throw new TooManyRequestsHttpException(
                    $exception->retryAfterSeconds,
                    'Politics & War is rate limiting raid data requests.',
                    $exception,
                );
            }

            throw new ServiceUnavailableHttpException(
                null,
                'Raid data is temporarily unavailable.',
                $exception,
            );
        } catch (Throwable $exception) {
            Log::warning('Raid Finder could not query Politics & War.', [
                'exception' => $exception::class,
            ]);

            throw new ServiceUnavailableHttpException(
                null,
                'Raid data is temporarily unavailable.',
                $exception,
            );
        }

        $nationModels = collect();
        foreach ($results as $json) {
            $nation = new \App\GraphQL\Models\Nation;
            $nation->buildWithJSON((object) $json);
            $nationModels->push($nation);
        }

        return $nationModels;
    }
}

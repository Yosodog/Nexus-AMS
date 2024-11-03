<?php

namespace App\Services;

use App\Exceptions\PWEntityDoesNotExist;
use App\GraphQL\Models\Nation;
use App\GraphQL\Models\Nations;

class NationQueryService
{
    /**
     * @param int $nID
     * @return Nation
     * @throws \App\Exceptions\PWQueryFailedException
     * @throws \App\Exceptions\PWRateLimitHitException
     */
    public static function getNationById(int $nID): Nation
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("nations")
            ->addArgument('id', $nID)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::nationSet());
            });

        $response = $client->sendQuery($builder);

        if (!isset($response->{0}))
            throw new PWEntityDoesNotExist();

        $nation = new Nation();
        $nation->buildWithJSON((object)$response->{0});

        return $nation;
    }

    /**
     * @param int $nID
     * @return Nation
     * @throws \App\Exceptions\PWQueryFailedException
     * @throws \App\Exceptions\PWRateLimitHitException
     */
    public static function getNationAndCitiesById(int $nID): Nation
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("nations")
            ->addArgument('id', $nID)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::nationSet())
                    ->addNestedField("cities", function(GraphQLQueryBuilder $cityBuilder) {
                        $cityBuilder->addFields(SelectionSetHelper::citySet());
                    });
            });

        $response = $client->sendQuery($builder);

        $nation = new Nation();
        $nation->buildWithJSON((object)$response->{0});

        return $nation;
    }

    /**
     * @param array $arguments
     * @param int $perPage
     * @param bool $withCities
     * @return Nations
     * @throws \App\Exceptions\PWQueryFailedException
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public static function getMultipleNations(array $arguments, int $perPage = 500, bool $withCities = false): Nations
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("nations")
            ->addArgument('first', $perPage)
            ->addArgument($arguments)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) use ($withCities) {
                if ($withCities) {
                    $builder->addFields(SelectionSetHelper::nationSet())
                        ->addNestedField("cities", function(GraphQLQueryBuilder $cityBuilder) {
                            $cityBuilder->addFields(SelectionSetHelper::citySet());
                        });
                } else {
                    $builder->addFields(SelectionSetHelper::nationSet());
                }
            })
            ->withPaginationInfo();

        $response = $client->sendQuery($builder);
        $nations = new Nations();

        foreach ($response as $queryNation)
        {
            $nation = new Nation();
            $nation->buildWithJSON((object)$queryNation);
            $nations->add($nation);
        }

        return $nations;
    }
}

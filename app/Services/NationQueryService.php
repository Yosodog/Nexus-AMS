<?php

namespace App\Services;

use App\GraphQL\Models\Nation;

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

        $nation = new Nation();
        $nation->buildWithJSON((object)$response['data']['nations']['data'][0]);

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
        $nation->buildWithJSON((object)$response['data']['nations']['data'][0]);

        return $nation;
    }
}

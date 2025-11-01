<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Exceptions\PWRateLimitHitException;
use App\GraphQL\Models\City;

class CityQueryService
{
    /**
     * @param int $cID
     * @return City
     * @throws PWQueryFailedException
     * @throws PWRateLimitHitException
     */
    public static function getCityById(int $cID): City
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("cities")
            ->addArgument('id', $cID)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::citySet());
            });

        $response = $client->sendQuery($builder);

        $alliance = new City();
        $alliance->buildWithJSON((object)$response->{0});

        return $alliance;
    }
}

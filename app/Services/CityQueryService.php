<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Exceptions\PWRateLimitHitException;
use App\GraphQL\Models\City;
use App\GraphQL\Models\Cities;

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

    /**
     * Fetch multiple cities in a single query without pagination handling.
     *
     * @param array<string, mixed> $arguments
     * @param int $perPage
     * @return Cities
     */
    public static function getMultipleCities(array $arguments, int $perPage = 100): Cities
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField('cities')
            ->addArgument('first', $perPage)
            ->addArgument($arguments)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::citySet());
            });

        $response = $client->sendQuery($builder, handlePagination: false);
        $cities = new Cities([]);

        foreach ($response as $city) {
            $model = new City();
            $model->buildWithJSON((object)$city);
            $cities->add($model);
        }

        return $cities;
    }
}

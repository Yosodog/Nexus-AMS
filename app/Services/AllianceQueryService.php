<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Exceptions\PWRateLimitHitException;
use App\GraphQL\Models\Alliance;

class AllianceQueryService
{
    /**
     * @param int $aID
     * @return Alliance
     * @throws \App\Exceptions\PWQueryFailedException|\Illuminate\Http\Client\ConnectionException
     */
    public static function getAllianceById(int $aID): Alliance
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("alliances")
            ->addArgument('id', $aID)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet());
            });

        $response = $client->sendQuery($builder);

        $alliance = new Alliance();
        $alliance->buildWithJSON((object)$response->{0});

        return $alliance;
    }

    /**
     * Will get an alliance with all associated members
     *
     * @param int $aID
     * @return Alliance
     * @throws PWQueryFailedException
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public static function getAllianceWithMembersById(int $aID): Alliance
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("alliances")
            ->addArgument('id', $aID)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet())
                    ->addNestedField("nations", function (GraphQLQueryBuilder $nationBuilder) {
                        $nationBuilder->addFields(SelectionSetHelper::nationSet());
                    });
            });

        $response = $client->sendQuery($builder);

        $alliance = new Alliance();
        $alliance->buildWithJSON((object)$response->{0});

        return $alliance;
    }
}

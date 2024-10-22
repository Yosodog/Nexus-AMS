<?php

namespace App\Services;

use App\GraphQL\Models\Nation;

class NationQueryService
{
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
}

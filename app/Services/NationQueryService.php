<?php

namespace App\Services;

use App\GraphQL\Models\Nation;

class NationQueryService
{
    public QueryService $client;

    public function __construct()
    {
        $this->client = new QueryService();
    }

    public function getNationById(int $nID): Nation
    {
        $builder = (new GraphQLQueryBuilder())
            ->setRootField("nations")
            ->addArgument('id', $nID)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::nationSet());
            });

        $response = $this->client->sendQuery($builder);

        $nation = new Nation();
        $nation->buildWithJSON((object)$response['data']['nations']['data'][0]);

        return $nation;
    }
}

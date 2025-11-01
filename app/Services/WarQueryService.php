<?php

namespace App\Services;

use App\GraphQL\Models\War;
use App\GraphQL\Models\Wars;

class WarQueryService
{
    public static function getMultipleWars(
        array $arguments,
        int $perPage = 1000,
        bool $pagination = true,
        bool $handlePagination = true
    ): Wars {
        $client = new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('wars')
            ->addArgument('first', $perPage)
            ->addArgument($arguments)
            ->addNestedField(
                'data',
                fn (GraphQLQueryBuilder $builder) => $builder->addFields(SelectionSetHelper::warSet())
            );

        if ($pagination) {
            $builder->withPaginationInfo();
        }

        $response = $client->sendQuery($builder, handlePagination: $handlePagination);
        $wars = new Wars([]);

        foreach ($response as $result) {
            $war = new War;
            $war->buildWithJSON((object) $result);
            $wars->add($war);
        }

        return $wars;
    }
}

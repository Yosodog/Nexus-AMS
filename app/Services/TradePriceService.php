<?php

namespace App\Services;

use App\GraphQL\Models\TradePrice as TradePriceGraphQL;
use App\Models\TradePrice;

class TradePriceService
{
    /**
     * Return the most recent row from the database.
     */
    public function getLatest(): TradePrice
    {
        return TradePrice::latest('created_at')->firstOrFail();
    }

    /**
     * Calculate the average of each resource across the last 24 rows (i.e., ~24 hours).
     */
    public function get24hAverage(): TradePrice
    {
        $rows = TradePrice::latest('created_at')->take(24)->get();

        $avg = new TradePrice();
        foreach (PWHelperService::resources(includeCredits: true) as $resource) {
            $avg->{$resource} = (int) round($rows->avg($resource));
        }

        return $avg;
    }

    /**
     * Pull the latest trade prices from the Politics & War GraphQL API.
     */
    public function pullFromGraphQL(): TradePriceGraphQL
    {
        $query = (new GraphQLQueryBuilder())
            ->setRootField('tradeprices')
            ->addArgument('first', 1)
            ->addNestedField('data', function(GraphQLQueryBuilder $builder) {
                $builder->addFields([
                    'id',
                    'date',
                    'coal',
                    'oil',
                    'uranium',
                    'iron',
                    'bauxite',
                    'lead',
                    'gasoline',
                    'munitions',
                    'steel',
                    'aluminum',
                    'food',
                    'credits'
                ]);
            });

        $response = (new QueryService())->sendQuery($query);

        $model = new TradePriceGraphQL();
        $model->buildWithJSON((object) $response->{0});

        return $model;
    }
}
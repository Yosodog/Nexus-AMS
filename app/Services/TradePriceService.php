<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\GraphQL\Models\TradePrice as TradePriceGraphQL;
use App\Models\TradePrice;
use Illuminate\Http\Client\ConnectionException;

class TradePriceService
{
    /**
     * @return TradePrice
     */
    public function getLatest(): TradePrice
    {
        return TradePrice::latest('created_at')->firstOrFail();
    }

    /**
     * @return TradePrice
     */
    public function get24hAverage(): TradePrice
    {
        $rows = TradePrice::latest('created_at')->take(24)->get();

        $avg = new TradePrice();
        foreach (PWHelperService::resources(includeCredits: true) as $resource) {
            $avg->{$resource} = (int)round($rows->avg($resource));
        }

        return $avg;
    }

    /**
     * @return TradePriceGraphQL
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function pullFromGraphQL(): TradePriceGraphQL
    {
        $query = (new GraphQLQueryBuilder())
            ->setRootField('tradeprices')
            ->addArgument('first', 1)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
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
        $model->buildWithJSON((object)$response->{0});

        return $model;
    }
}
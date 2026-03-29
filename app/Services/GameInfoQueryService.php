<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use Illuminate\Http\Client\ConnectionException;

class GameInfoQueryService
{
    /**
     * @return array<string, float>
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public function getRadiation(): array
    {
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('game_info')
            ->addNestedField('radiation', function (GraphQLQueryBuilder $builder) {
                $builder->addFields([
                    'global',
                    'north_america',
                    'south_america',
                    'europe',
                    'africa',
                    'asia',
                    'australia',
                    'antarctica',
                ]);
            });

        $response = (new QueryService)->sendQuery($builder, headers: false, handlePagination: false);
        $radiation = (array) ($response->radiation ?? []);

        return [
            'global' => (float) ($radiation['global'] ?? 0.0),
            'north_america' => (float) ($radiation['north_america'] ?? 0.0),
            'south_america' => (float) ($radiation['south_america'] ?? 0.0),
            'europe' => (float) ($radiation['europe'] ?? 0.0),
            'africa' => (float) ($radiation['africa'] ?? 0.0),
            'asia' => (float) ($radiation['asia'] ?? 0.0),
            'australia' => (float) ($radiation['australia'] ?? 0.0),
            'antarctica' => (float) ($radiation['antarctica'] ?? 0.0),
        ];
    }
}

<?php

namespace App\Services;

use App\Exceptions\PWEntityDoesNotExist;
use App\Exceptions\PWQueryFailedException;
use App\Exceptions\PWRateLimitHitException;
use App\GraphQL\Models\Nation;
use App\GraphQL\Models\Nations;
use Generator;
use Illuminate\Http\Client\ConnectionException;

class NationQueryService
{
    /**
     * @throws PWQueryFailedException
     * @throws PWRateLimitHitException
     */
    public static function getNationById(int $nID): Nation
    {
        $client = new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('nations')
            ->addArgument('id', $nID)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                SelectionSetHelper::applyNationSelection($builder);
            });

        $response = $client->sendQuery($builder);

        if (! isset($response->{0})) {
            throw new PWEntityDoesNotExist;
        }

        $nation = new Nation;
        $nation->buildWithJSON((object) $response->{0});

        return $nation;
    }

    /**
     * @throws PWQueryFailedException
     * @throws PWRateLimitHitException
     */
    public static function getNationAndCitiesById(int $nID): Nation
    {
        $client = new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('nations')
            ->addArgument('id', $nID)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                SelectionSetHelper::applyNationSelection($builder)
                    ->addNestedField('cities', function (GraphQLQueryBuilder $cityBuilder): void {
                        $cityBuilder->addFields(SelectionSetHelper::citySet());
                    });
            });

        $response = $client->sendQuery($builder);

        if (! isset($response->{0})) {
            throw new PWEntityDoesNotExist;
        }

        $nation = new Nation;
        $nation->buildWithJSON((object) $response->{0});

        return $nation;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public static function getMultipleNations(
        array $arguments,
        int $perPage = 500,
        bool $withCities = false,
        bool $pagination = true,
        bool $handlePagination = true
    ): Nations {
        $client = new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('nations')
            ->addArgument('first', $perPage)
            ->addArgument($arguments)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) use ($withCities) {
                SelectionSetHelper::applyNationSelection($builder);

                if ($withCities) {
                    $builder->addNestedField('cities', function (GraphQLQueryBuilder $cityBuilder): void {
                        $cityBuilder->addFields(SelectionSetHelper::citySet());
                    });
                }
            });

        if ($pagination) {
            $builder->withPaginationInfo();
        }

        $response = $client->sendQuery($builder, handlePagination: $handlePagination);
        $nations = new Nations;

        foreach ($response as $queryNation) {
            $nation = new Nation;
            $nation->buildWithJSON((object) $queryNation);
            $nations->add($nation);
        }

        return $nations;
    }

    /**
     * Fetch an exact nation set with bounded concurrency and memory use.
     *
     * @param  list<int>  $nationIds
     * @return Generator<int, Nation>
     */
    public static function getMultipleNationsByIdsConcurrently(
        array $nationIds,
        int $maxConcurrency = 2,
    ): Generator {
        $builders = [];

        foreach (array_chunk(array_values(array_unique(array_map('intval', $nationIds))), 500) as $chunk) {
            $builders[] = (new GraphQLQueryBuilder)
                ->setRootField('nations')
                ->addArgument('first', count($chunk))
                ->addArgument(['id' => $chunk])
                ->addNestedField('data', function (GraphQLQueryBuilder $builder): void {
                    SelectionSetHelper::applyNationSelection($builder);
                });
        }

        $client = new QueryService;

        foreach ($client->sendQueriesConcurrently($builders, $maxConcurrency) as $response) {
            foreach ($response as $queryNation) {
                $nation = new Nation;
                $nation->buildWithJSON((object) $queryNation);

                yield $nation;
            }
        }
    }

    /**
     * Fetch one page of nations without hydrating GraphQL model objects.
     *
     * This path is intended for bulk synchronization, where the response is
     * immediately transformed into database rows.
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $nationFields
     * @param  list<string>  $cityFields
     * @param  array<string, list<string>>  $nationNestedFields
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public static function getRawNationPage(
        array $arguments,
        int $perPage,
        array $nationFields,
        array $cityFields = [],
        array $nationNestedFields = [],
    ): array {
        $client = new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('nations')
            ->addArgument('first', $perPage)
            ->addArgument($arguments)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) use ($nationFields, $cityFields, $nationNestedFields) {
                $builder->addFields($nationFields);

                foreach ($nationNestedFields as $field => $fields) {
                    $builder->addNestedField($field, function (GraphQLQueryBuilder $nestedBuilder) use ($fields): void {
                        $nestedBuilder->addFields($fields);
                    });
                }

                if ($cityFields !== []) {
                    $builder->addNestedField('cities', function (GraphQLQueryBuilder $cityBuilder) use ($cityFields): void {
                        $cityBuilder->addFields($cityFields);
                    });
                }
            });

        $response = $client->sendQuery($builder, handlePagination: false);

        return array_map(
            static fn (mixed $nation): array => (array) $nation,
            array_values((array) $response)
        );
    }
}

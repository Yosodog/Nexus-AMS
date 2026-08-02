<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\GraphQL\Models\Alliance;
use App\GraphQL\Models\Alliances;
use Illuminate\Http\Client\ConnectionException;

class AllianceQueryService
{
    /**
     * @throws PWQueryFailedException|ConnectionException
     */
    public static function getAllianceById(int $aID): Alliance
    {
        $client = new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('alliances')
            ->addArgument('id', $aID)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet());
            });

        $response = $client->sendQuery($builder);

        return self::hydrateSingleAlliance($response, $aID);
    }

    /**
     * Will get an alliance with all associated members
     *
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public static function getAllianceWithMembersById(int $aID, ?QueryService $client = null): Alliance
    {
        $client ??= new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('alliances')
            ->addArgument('id', $aID)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet())
                    ->addNestedField('nations', function (GraphQLQueryBuilder $nationBuilder) {
                        SelectionSetHelper::applyNationSelection($nationBuilder);
                    });
            });

        $response = $client->sendQuery($builder);

        return self::hydrateSingleAlliance($response, $aID);
    }

    /**
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public static function getMultipleAlliances(
        array $arguments,
        int $perPage = 500,
        bool $pagination = true,
        bool $handlePagination = true
    ): Alliances {
        $client = new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('alliances')
            ->addArgument('first', $perPage)
            ->addArgument($arguments)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet());
            });

        if ($pagination) {
            $builder->withPaginationInfo();
        }

        $response = $client->sendQuery($builder, handlePagination: $handlePagination);
        $alliances = new Alliances([]);

        foreach ($response as $queryAlliance) {
            $alliance = new Alliance;
            $alliance->buildWithJSON((object) $queryAlliance);
            $alliances->add($alliance);
        }

        return $alliances;
    }

    /**
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public static function getAllianceWithTaxes(int $aID, ?QueryService $client = null): Alliance
    {
        $client ??= new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('alliances')
            ->addArgument('id', $aID)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet())
                    ->addNestedField('taxrecs', function (GraphQLQueryBuilder $nationBuilder) {
                        $nationBuilder->addFields(SelectionSetHelper::bankRecordSet());
                    });
            });

        $response = $client->sendQuery($builder);

        return self::hydrateSingleAlliance($response, $aID);
    }

    /** @throws PWQueryFailedException */
    private static function hydrateSingleAlliance(mixed $response, int $allianceId): Alliance
    {
        $record = is_object($response)
            ? ($response->{0} ?? null)
            : (is_array($response) ? ($response[0] ?? null) : null);
        $recordData = is_object($record) || is_array($record) ? (array) $record : [];

        if (! isset($recordData['id'])
            || ! is_numeric($recordData['id'])
            || (int) $recordData['id'] !== $allianceId) {
            throw new PWQueryFailedException(
                "Politics & War returned no usable data for alliance [{$allianceId}]."
            );
        }

        $alliance = new Alliance;
        $alliance->buildWithJSON((object) $recordData);

        return $alliance;
    }
}

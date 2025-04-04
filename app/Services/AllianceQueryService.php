<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\GraphQL\Models\Alliance;
use App\GraphQL\Models\Alliances;
use Illuminate\Http\Client\ConnectionException;

class AllianceQueryService
{
    /**
     * @param int $aID
     * @return Alliance
     * @throws PWQueryFailedException|ConnectionException
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
     * @throws ConnectionException
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

    /**
     * @param array $arguments
     * @param int $perPage
     * @param bool $pagination
     * @param bool $handlePagination
     * @return Alliances
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public static function getMultipleAlliances(
        array $arguments,
        int $perPage = 500,
        bool $pagination = true,
        bool $handlePagination = true
    ): Alliances {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("alliances")
            ->addArgument('first', $perPage)
            ->addArgument($arguments)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet());
            });

        if ($pagination) {
            $builder->withPaginationInfo();
        }

        $response = $client->sendQuery($builder, handlePagination: $handlePagination);
        $alliances = new Alliances([]);

        foreach ($response as $queryAlliance) {
            $alliance = new Alliance();
            $alliance->buildWithJSON((object)$queryAlliance);
            $alliances->add($alliance);
        }

        return $alliances;
    }

    /**
     * @param int $aID
     * @return Alliance
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public static function getAllianceWithTaxes(int $aID): Alliance
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("alliances")
            ->addArgument('id', $aID)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::allianceSet())
                    ->addNestedField("taxrecs", function (GraphQLQueryBuilder $nationBuilder) {
                        $nationBuilder->addFields(SelectionSetHelper::bankRecordSet());
                    });
            });

        $response = $client->sendQuery($builder);

        $alliance = new Alliance();
        $alliance->buildWithJSON((object)$response->{0});

        return $alliance;
    }
}

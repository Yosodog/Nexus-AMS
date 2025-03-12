<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\GraphQL\Models\BankRecord;
use App\GraphQL\Models\BankRecords;
use Illuminate\Http\Client\ConnectionException;

class BankRecordQueryService
{
    /**
     * Will get all deposits into the alliance bank.
     *
     * @param int $aID
     * @param int $perQuery
     * @return BankRecords
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public static function getAllianceDeposits(int $aID, int $perQuery = 500): BankRecords
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("bankrecs")
            ->addArgument('first', $perQuery)
            ->addArgument('rid', $aID)
            ->addArgument('rtype', 2)
            ->addNestedField("data", function (GraphQLQueryBuilder $builder) {
                $builder->addFields(SelectionSetHelper::bankRecordSet());
            });

        // TODO the withdraw works but it errors out because of the response

        $response = $client->sendQuery($builder);

        $bankRecs = new BankRecords([]);

        foreach ($response as $queryRecs) {
            $bankRec = new BankRecord();
            $bankRec->buildWithJSON((object)$queryRecs);
            $bankRecs->add($bankRec);
        }

        return $bankRecs;
    }
}

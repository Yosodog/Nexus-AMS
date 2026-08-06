<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\GraphQL\Models\BankRecord;
use App\GraphQL\Models\BankRecords;
use Illuminate\Http\Client\ConnectionException;

class TaxRecordQueryService
{
    public const DEFAULT_LIMIT = 500;

    /**
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function getAllianceTaxes(
        int $allianceId,
        int $minimumId = 1,
        int $limit = self::DEFAULT_LIMIT,
        ?QueryService $client = null
    ): BankRecords {
        $client ??= new QueryService;
        $limit = max(1, min(self::DEFAULT_LIMIT, $limit));
        $nextMinimumId = max(1, $minimumId);
        $recordsById = [];

        do {
            $response = $client->sendQuery($this->buildQuery($allianceId, $nextMinimumId, $limit));
            $page = $this->extractTaxRecords($response, $allianceId);
            $highestId = $nextMinimumId - 1;

            foreach ($page as $recordData) {
                $record = new BankRecord;
                $record->buildWithJSON((object) $recordData);

                $recordsById[$record->id] = $record;
                $highestId = max($highestId, $record->id);
            }

            if (count($page) < $limit) {
                break;
            }

            if ($highestId < $nextMinimumId) {
                throw new PWQueryFailedException(
                    "Politics & War tax records did not advance for alliance [{$allianceId}]."
                );
            }

            $nextMinimumId = $highestId + 1;
        } while (true);

        ksort($recordsById, SORT_NUMERIC);

        return new BankRecords(array_values($recordsById));
    }

    private function buildQuery(int $allianceId, int $minimumId, int $limit): GraphQLQueryBuilder
    {
        return (new GraphQLQueryBuilder)
            ->setRootField('alliances')
            ->addArgument('id', $allianceId)
            ->addNestedField('data', function (GraphQLQueryBuilder $builder) use ($minimumId, $limit): void {
                $builder->addFields(['id'])
                    ->addNestedField('taxrecs', function (GraphQLQueryBuilder $builder) use ($minimumId, $limit): void {
                        $builder->addArgument('min_id', $minimumId)
                            ->addArgument('limit', $limit)
                            ->addArgument('orderBy', [[
                                'column' => GraphQLQueryBuilder::literal('ID'),
                                'order' => GraphQLQueryBuilder::literal('ASC'),
                            ]])
                            ->addFields(SelectionSetHelper::bankRecordSet());
                    });
            });
    }

    /**
     * @return list<array<string, mixed>|object>
     *
     * @throws PWQueryFailedException
     */
    private function extractTaxRecords(object $response, int $allianceId): array
    {
        foreach ((array) $response as $allianceData) {
            $alliance = (array) $allianceData;

            if (isset($alliance['id'])
                && is_numeric($alliance['id'])
                && (int) $alliance['id'] === $allianceId) {
                if (! array_key_exists('taxrecs', $alliance) || ! is_array($alliance['taxrecs'])) {
                    throw new PWQueryFailedException(
                        "Politics & War returned unusable tax records for alliance [{$allianceId}]."
                    );
                }

                return array_values($alliance['taxrecs']);
            }
        }

        throw new PWQueryFailedException(
            "Politics & War returned no usable data for alliance [{$allianceId}]."
        );
    }
}

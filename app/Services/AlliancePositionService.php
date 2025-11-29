<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class AlliancePositionService
{
    public function __construct(private readonly QueryService $client) {}

    /**
     * Promote an applicant to the configured member position.
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public function approveMember(int $nationId): void
    {
        $positionId = SettingService::getApplicationsApprovedPositionId();

        if ($positionId <= 0) {
            throw new RuntimeException('Applications approved position ID is not configured.');
        }

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('assignAlliancePosition')
            ->setMutation()
            ->addArgument('id', $nationId)
            ->addArgument('position_id', $positionId)
            ->addFields(['id']);

        $this->client->sendQuery($builder, headers: true);
    }

    /**
     * Remove a nation from the alliance.
     *
     * @throws ConnectionException
     * @throws PWQueryFailedException
     */
    public function removeMember(int $nationId): void
    {
        $builder = (new GraphQLQueryBuilder)
            ->setRootField('assignAlliancePosition')
            ->setMutation()
            ->addArgument('id', $nationId)
            ->addArgument('default_position', GraphQLQueryBuilder::literal('REMOVE'))
            ->addFields(['id']);

        $this->client->sendQuery($builder, headers: true);
    }
}

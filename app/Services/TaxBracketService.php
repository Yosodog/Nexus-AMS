<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Jobs\AssignTaxBracket;
use App\Models\Nation;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class TaxBracketService
{
    public int $id;

    public int $target_id;

    public ?int $alliance_id = null;

    /**
     * Dispatches the job to assign a tax bracket asynchronously.
     */
    public function send(): void
    {
        AssignTaxBracket::dispatch($this);
    }

    /**
     * Sends the mutation to assign the tax bracket directly.
     *
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function sendAssign(): void
    {
        $allianceId = $this->alliance_id ?? (int) Nation::query()
            ->whereKey($this->target_id)
            ->value('alliance_id');

        if ($allianceId <= 0) {
            throw new RuntimeException("Cannot resolve an alliance for nation {$this->target_id}.");
        }

        $membershipService = app(AllianceMembershipService::class);
        $credentials = $membershipService->getCredentialsForAlliance($allianceId);
        if ($credentials === null) {
            throw new RuntimeException("API credentials are not configured for alliance {$allianceId}.");
        }

        $primaryAllianceId = $membershipService->getPrimaryAllianceId();
        if ($allianceId !== $primaryAllianceId && blank($credentials['mutation_key'])) {
            throw new RuntimeException("Mutation credentials are not configured for offshore alliance {$allianceId}.");
        }

        $parameters = [
            'apiKey' => $credentials['api_key'],
        ];

        if (! blank($credentials['mutation_key'])) {
            $parameters['mutationKey'] = $credentials['mutation_key'];
        }

        $client = app(QueryService::class, $parameters);

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('assignTaxBracket')
            ->setMutation()
            ->addArgument('id', $this->id)
            ->addArgument('target_id', $this->target_id)
            ->addFields(['id', 'tax_rate', 'resource_tax_rate']);

        $client->sendQuery($builder, headers: true);
    }
}

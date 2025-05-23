<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Jobs\AssignTaxBracket;
use Illuminate\Http\Client\ConnectionException;

class TaxBracketService
{
    public int $id;         // Tax bracket ID to assign
    public int $target_id;  // Nation ID

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
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField('assignTaxBracket')
            ->setMutation()
            ->addArgument('id', $this->id)
            ->addArgument('target_id', $this->target_id)
            ->addFields(['id', 'name', 'rate']);

        $client->sendQuery($builder);
    }
}
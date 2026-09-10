<?php

namespace App\Services;

use App\Exceptions\DefiniteMutationFailureException;
use App\Exceptions\PWQueryFailedException;
use App\Jobs\AssignTaxBracket;
use App\Models\Offshore;
use Illuminate\Http\Client\ConnectionException;

class TaxBracketService
{
    public int $id;         // Tax bracket ID to assign

    public int $target_id;  // Nation ID

    public ?int $offshore_id = null;

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
        $client = $this->queryClient();

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('assignTaxBracket')
            ->setMutation()
            ->addArgument('id', $this->id)
            ->addArgument('target_id', $this->target_id)
            ->addFields(['id', 'tax_rate', 'resource_tax_rate']);

        $client->sendQuery($builder, headers: true);
    }

    private function queryClient(): QueryService
    {
        if ($this->offshore_id === null) {
            return app(QueryService::class);
        }

        $offshore = Offshore::query()->find($this->offshore_id);

        if (! $offshore || ! $offshore->api_key_decrypted || ! $offshore->mutation_key_decrypted) {
            throw new DefiniteMutationFailureException(
                'The offshore credentials required to assign a tax bracket are unavailable.'
            );
        }

        return new QueryService(
            apiKey: $offshore->api_key_decrypted,
            mutationKey: $offshore->mutation_key_decrypted,
        );
    }
}

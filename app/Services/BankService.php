<?php

namespace App\Services;

use App\Exceptions\AmbiguousMutationOutcomeException;
use App\Exceptions\DefiniteMutationFailureException;
use App\Exceptions\PWQueryFailedException;
use App\GraphQL\Models\BankRecord;
use App\Jobs\SendBank;
use App\Models\Transaction;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class BankService
{
    public int $receiver;

    public int $receiver_type = 1;

    public float $money = 0;

    public float $coal = 0;

    public float $oil = 0;

    public float $uranium = 0;

    public float $iron = 0;

    public float $bauxite = 0;

    public float $lead = 0;

    public float $gasoline = 0;

    public float $munitions = 0;

    public float $steel = 0;

    public float $aluminum = 0;

    public float $food = 0;

    public string $note = '';

    /**
     * Queues a job to send the withdraw.
     */
    public function send(Transaction $transaction): void
    {
        SendBank::dispatch($this, $transaction);
    }

    /**
     * Send a bank withdraw request. This does not queue the request. You
     * should use send() instead to queue the withdraw.
     *
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function sendWithdraw(): BankRecord
    {
        if (! isset($this->receiver) || $this->receiver <= 0) {
            throw new DefiniteMutationFailureException('A valid withdrawal receiver is required.');
        }

        $hasResources = collect(PWHelperService::resources())
            ->contains(fn (string $resource): bool => $this->{$resource} > 0);

        if (! $hasResources) {
            throw new DefiniteMutationFailureException('A bank withdrawal must include at least one resource.');
        }

        $client = new QueryService;

        $builder = (new GraphQLQueryBuilder)
            ->setRootField('bankWithdraw')
            ->setMutation()
            ->addArgument('receiver', $this->receiver)
            ->addArgument('receiver_type', $this->receiver_type)
            ->addArgument('note', $this->note)
            ->addFields(SelectionSetHelper::bankRecordSet());

        // Add optional resource arguments if they are greater than 0
        foreach (array_filter(PWHelperService::resources(), fn ($r) => $this->$r > 0) as $resource) {
            $builder->addArgument($resource, $this->$resource);
        }

        $response = $client->sendQuery($builder, headers: true);

        try {
            $bankRec = new BankRecord;
            $bankRec->buildWithJSON((object) $response);
        } catch (Throwable $exception) {
            throw new AmbiguousMutationOutcomeException(
                'The bank mutation returned an invalid record after it may have succeeded.',
                previous: $exception,
            );
        }

        if ($bankRec->id <= 0 || $bankRec->receiver_id !== $this->receiver) {
            throw new AmbiguousMutationOutcomeException(
                'The bank mutation returned a record that could not be matched to the requested withdrawal.'
            );
        }

        foreach (PWHelperService::resources() as $resource) {
            if (abs($bankRec->{$resource} - $this->{$resource}) > 0.005) {
                throw new AmbiguousMutationOutcomeException(
                    'The bank mutation returned resource amounts that did not match the requested withdrawal.'
                );
            }
        }

        return $bankRec;
    }
}

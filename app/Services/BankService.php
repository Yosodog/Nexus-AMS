<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\GraphQL\Models\BankRecord;
use App\Jobs\SendBank;
use App\Models\Transactions;
use Illuminate\Http\Client\ConnectionException;

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
    public string $note = "";

    /**
     * Queues a job to send the withdraw.
     *
     * @param Transactions $transaction
     *
     * @return void
     */
    public function send(Transactions $transaction): void
    {
        SendBank::dispatch($this, $transaction);
    }

    /**
     * Send a bank withdraw request. This does not queue the request. You
     * should use send() instead to queue the withdraw.
     *
     * @return BankRecord
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function sendWithdraw(): BankRecord
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("bankWithdraw")
            ->setMutation()
            ->addArgument('receiver', $this->receiver)
            ->addArgument('receiver_type', $this->receiver_type)
            ->addArgument('note', $this->note)
            ->addFields(SelectionSetHelper::bankRecordSet());

        // Add optional resource arguments if they are greater than 0
        $optionalFields = [
            'money' => $this->money,
            'coal' => $this->coal,
            'oil' => $this->oil,
            'uranium' => $this->uranium,
            'iron' => $this->iron,
            'bauxite' => $this->bauxite,
            'lead' => $this->lead,
            'gasoline' => $this->gasoline,
            'munitions' => $this->munitions,
            'steel' => $this->steel,
            'aluminum' => $this->aluminum,
            'food' => $this->food,
        ];

        foreach ($optionalFields as $key => $value) {
            if ($value > 0) {
                $builder->addArgument($key, $value);
            }
        }

        $response = $client->sendQuery($builder, headers: true);

        $bankRec = new BankRecord();
        $bankRec->buildWithJSON((object)$response);

        return $bankRec;
    }

}

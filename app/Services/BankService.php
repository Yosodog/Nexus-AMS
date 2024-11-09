<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\GraphQL\Models\BankRecord;
use Illuminate\Http\Client\ConnectionException;

class BankService
{
    public int $receiver;
    public int $receiver_type;
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
    public ?string $note = null;

    /**ß
     * Send a bank withdraw request.
     *
     * @return BankRecord
     * @throws PWQueryFailedException
     * @throws ConnectionException
     */
    public function sendWithdraw()
    {
        $client = new QueryService();

        $builder = (new GraphQLQueryBuilder())
            ->setRootField("bankWithdraw")
            ->setMutation()
            ->addArgument('receiver', $this->receiver)
            ->addArgument('receiver_type', $this->receiver_type)
            ->addArgument('note', $this->note)
            ->addFields(['id', 'sender_id']);

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

        // Custom headers for the mutation
        $headers = [
            'X-Bot-Key' => env("PW_API_MUTATION_KEY"),
            'X-Api-Key' => env("PW_API_KEY"),
        ];


        $response = $client->sendQuery($builder, [], null, $headers);

        $bankRec = new BankRecord();
        $bankRec->buildWithJSON((object)$response->{0});

        return $bankRec;
    }
}

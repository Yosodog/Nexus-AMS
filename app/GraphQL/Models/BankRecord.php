<?php

namespace App\GraphQL\Models;

class BankRecord
{
    public int $id;
    public string $date;
    public int $sender_id;
    public int $sender_type;
    public Nation $sender;
    public int $receiver_id;
    public int $receiver_type;
    public Nation $receiver;
    public int $banker_id;
    public Nation $banker;
    public ?string $note;
    public float $money;
    public float $coal;
    public float $oil;
    public float $uranium;
    public float $iron;
    public float $bauxite;
    public float $lead;
    public float $gasoline;
    public float $munitions;
    public float $steel;
    public float $aluminum;
    public float $food;
    public int $tax_id;

    /**
     * Populate the Bankrec instance with JSON data.
     *
     * @param \stdClass $json
     * @return void
     */
    public function buildWithJSON(\stdClass $json): void
    {
        $this->id = $json->id;
        $this->date = $json->date;
        $this->sender_id = $json->sender_id;
        $this->sender_type = $json->sender_type;
        $this->receiver_id = $json->receiver_id;
        $this->receiver_type = $json->receiver_type;
        $this->banker_id = $json->banker_id;
        $this->note = $json->note ?? null;
        $this->money = $json->money ?? 0.0;
        $this->coal = $json->coal ?? 0.0;
        $this->oil = $json->oil ?? 0.0;
        $this->uranium = $json->uranium ?? 0.0;
        $this->iron = $json->iron ?? 0.0;
        $this->bauxite = $json->bauxite ?? 0.0;
        $this->lead = $json->lead ?? 0.0;
        $this->gasoline = $json->gasoline ?? 0.0;
        $this->munitions = $json->munitions ?? 0.0;
        $this->steel = $json->steel ?? 0.0;
        $this->aluminum = $json->aluminum ?? 0.0;
        $this->food = $json->food ?? 0.0;
        $this->tax_id = $json->tax_id ?? 0;
    }
}

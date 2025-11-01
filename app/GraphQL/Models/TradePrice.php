<?php

namespace App\GraphQL\Models;

use stdClass;

class TradePrice
{
    public string $id;

    public string $date;

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

    public float $credits;

    public function buildWithJSON(stdClass $json): void
    {
        $this->id = $json->id;
        $this->date = $json->date;

        $this->coal = $json->coal;
        $this->oil = $json->oil;
        $this->uranium = $json->uranium;
        $this->iron = $json->iron;
        $this->bauxite = $json->bauxite;
        $this->lead = $json->lead;
        $this->gasoline = $json->gasoline;
        $this->munitions = $json->munitions;
        $this->steel = $json->steel;
        $this->aluminum = $json->aluminum;
        $this->food = $json->food;
        $this->credits = $json->credits;
    }
}

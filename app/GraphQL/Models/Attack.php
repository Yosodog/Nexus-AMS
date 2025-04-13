<?php

namespace App\GraphQL\Models;

class Attack
{
    public ?int $money_looted = 0;
    public ?int $money_stolen = 0;
    public ?int $coal_looted = 0;
    public ?int $oil_looted = 0;
    public ?int $uranium_looted = 0;
    public ?int $iron_looted = 0;
    public ?int $bauxite_looted = 0;
    public ?int $lead_looted = 0;
    public ?int $gasoline_looted = 0;
    public ?int $munitions_looted = 0;
    public ?int $steel_looted = 0;
    public ?int $aluminum_looted = 0;
    public ?int $food_looted = 0;

    public function buildWithJSON(\stdClass $json): void
    {
        foreach ($json as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
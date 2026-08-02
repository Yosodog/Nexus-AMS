<?php

namespace App\GraphQL\Models;

use stdClass;

final class Treasure
{
    public ?string $name = null;

    public ?int $bonus = null;

    public ?int $nation_id = null;

    public function buildWithJSON(stdClass $json): void
    {
        $this->name = isset($json->name) ? (string) $json->name : null;
        $this->bonus = isset($json->bonus) ? (int) $json->bonus : null;
        $this->nation_id = isset($json->nation_id) ? (int) $json->nation_id : null;
    }
}

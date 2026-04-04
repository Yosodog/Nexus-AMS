<?php

namespace App\GraphQL\Models;

use App\Services\ApiDateNormalizer;
use stdClass;

class Treaty
{
    public int $id;

    public ?string $date = null;

    public int $turns_left;

    public int $alliance1_id;

    public int $alliance2_id;

    public string $treaty_type;

    public bool $approved;

    public function buildWithJSON(stdClass $json): void
    {
        $this->id = (int) $json->id;
        $this->date = ApiDateNormalizer::normalizeTimestamp($json->date);
        $this->treaty_type = $json->treaty_type;
        $this->turns_left = $json->turns_left;
        $this->alliance1_id = $json->alliance1_id;
        $this->alliance2_id = $json->alliance2_id;
        $this->approved = (bool) $json->approved;
    }
}

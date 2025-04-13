<?php

namespace App\GraphQL\Models;

use Carbon\Carbon;
use stdClass;

class Treaty
{
    public int $id;
    public string $date;
    public int $turns_left;
    public int $alliance1_id;
    public int $alliance2_id;
    public string $treaty_type;
    public bool $approved;

    /**
     * @param stdClass $json
     * @return void
     */
    public function buildWithJSON(stdClass $json): void
    {
        $this->id = (int)$json->id;
        $this->date = Carbon::parse($json->date)->format('Y-m-d H:i:s'); // ✅ convert for MySQL
        $this->treaty_type = $json->treaty_type;
        $this->turns_left = $json->turns_left;
        $this->alliance1_id = $json->alliance1_id;
        $this->alliance2_id = $json->alliance2_id;
        $this->approved = (bool)$json->approved;
    }
}
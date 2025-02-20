<?php

namespace App\Services;

use Illuminate\Support\Str;

class DepositService
{

    /**
     * @return string
     */
    public static function generate_code(): string
    {
        return strtoupper(Str::random(8));
    }
}

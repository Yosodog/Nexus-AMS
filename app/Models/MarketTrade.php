<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketTrade extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'price' => 'integer',
            'posted_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}

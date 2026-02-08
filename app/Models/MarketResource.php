<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketResource extends Model
{
    protected $fillable = [
        'resource',
        'is_enabled',
        'adjustment_percent',
        'buy_cap_remaining',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'adjustment_percent' => 'decimal:2',
        'buy_cap_remaining' => 'decimal:2',
    ];
}

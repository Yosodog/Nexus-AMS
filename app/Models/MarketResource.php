<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketResource extends Model
{
    public const MIN_ADJUSTMENT_PERCENT = -99.99;

    public const MAX_ADJUSTMENT_PERCENT = 100.00;

    public const MAX_BUY_CAP_REMAINING = 100_000_000.00;

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

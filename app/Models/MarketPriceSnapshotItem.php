<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketPriceSnapshotItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acquisition_price' => 'float',
            'liquidation_price' => 'float',
            'acquisition_trade_count' => 'integer',
            'liquidation_trade_count' => 'integer',
            'acquisition_volume' => 'integer',
            'liquidation_volume' => 'integer',
            'acquisition_fallback' => 'boolean',
            'liquidation_fallback' => 'boolean',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MarketPriceSnapshot::class, 'market_price_snapshot_id');
    }
}

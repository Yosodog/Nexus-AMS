<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketPriceSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'window_started_at' => 'datetime',
            'window_ended_at' => 'datetime',
            'calculated_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketPriceSnapshotItem::class);
    }
}

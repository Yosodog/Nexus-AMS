<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthCircleDistribution extends Model
{
    // Append-only log — no updated_at column
    const UPDATED_AT = null;

    protected $fillable = [
        'nation_id',
        'food_sent',
        'uranium_sent',
        'food_level_before',
        'uranium_level_before',
        'city_count',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RebuildingEstimate extends Model
{
    protected $fillable = [
        'cycle_id',
        'nation_id',
        'city_count',
        'tier_id',
        'target_infrastructure',
        'estimated_amount',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'target_infrastructure' => 'float',
            'estimated_amount' => 'float',
            'calculated_at' => 'datetime',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(RebuildingTier::class, 'tier_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationProfitabilitySnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'resource_profit_per_day' => 'array',
            'converted_profit_per_day' => 'float',
            'money_profit_per_day' => 'float',
            'city_income_per_day' => 'float',
            'power_cost_per_day' => 'float',
            'food_cost_per_day' => 'float',
            'military_upkeep_per_day' => 'float',
            'calculated_at' => 'datetime',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }

    public function radiationSnapshot(): BelongsTo
    {
        return $this->belongsTo(RadiationSnapshot::class, 'radiation_snapshot_id');
    }
}

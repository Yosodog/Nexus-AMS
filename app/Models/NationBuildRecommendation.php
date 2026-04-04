<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationBuildRecommendation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recommended_build_json' => 'array',
            'resource_profit_per_day' => 'array',
            'land_used' => 'float',
            'converted_profit_per_day' => 'float',
            'money_profit_per_day' => 'float',
            'disease' => 'float',
            'crime' => 'float',
            'population' => 'integer',
            'commerce' => 'integer',
            'pollution' => 'integer',
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

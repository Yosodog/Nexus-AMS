<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RebuildingTier extends Model
{
    protected $fillable = [
        'name',
        'min_city_count',
        'max_city_count',
        'target_infrastructure',
        'is_active',
        'requirements',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_infrastructure' => 'float',
            'is_active' => 'boolean',
            'requirements' => 'array',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(RebuildingRequest::class, 'tier_id');
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(RebuildingEstimate::class, 'tier_id');
    }
}

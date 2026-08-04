<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomReadinessSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'payload' => 'array',
            'last_active_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    public function recommendationRun(): BelongsTo
    {
        return $this->belongsTo(MilcomRecommendationRun::class, 'recommendation_run_id');
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}

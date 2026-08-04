<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomObjectiveRecommendation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'team_score' => 'float',
            'confidence' => 'float',
            'proposed_team' => 'array',
            'alternatives' => 'array',
            'blocker_summary' => 'array',
            'factor_explanations' => 'array',
        ];
    }

    public function recommendationRun(): BelongsTo
    {
        return $this->belongsTo(MilcomRecommendationRun::class, 'recommendation_run_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MilcomObjective::class, 'objective_id');
    }
}

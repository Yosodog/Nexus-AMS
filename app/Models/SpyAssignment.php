<?php

namespace App\Models;

use App\Enums\SpyAssignmentStatus;
use App\Enums\SpyOperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a single aggressor-to-defender spy assignment.
 */
class SpyAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => SpyAssignmentStatus::class,
        'op_type' => SpyOperationType::class,
        'calculated_odds' => 'float',
        'expected_impact' => 'float',
        'policy_synergy' => 'float',
        'final_score_used_for_sorting' => 'float',
        'low_odds_flag' => 'bool',
    ];

    /**
     * @return BelongsTo<SpyRound, self>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(SpyRound::class, 'spy_round_id');
    }

    /**
     * @return BelongsTo<Nation, self>
     */
    public function attacker(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'attacker_nation_id');
    }

    /**
     * @return BelongsTo<Nation, self>
     */
    public function defender(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'defender_nation_id');
    }
}

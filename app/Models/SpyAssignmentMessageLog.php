<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks outbound messaging to avoid duplicate spy assignment notifications.
 */
class SpyAssignmentMessageLog extends Model
{
    protected $guarded = [];

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
}

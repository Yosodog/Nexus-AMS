<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proposed or finalized friendly assignment for a counter operation.
 *
 * @property int $id
 * @property int $war_counter_id
 * @property int $friendly_nation_id
 * @property float $match_score
 * @property string $status
 * @property bool $is_locked
 * @property array|null $meta
 */
class WarCounterAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'is_locked' => 'bool',
    ];

    /**
     * @return BelongsTo<WarCounter, self>
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(WarCounter::class, 'war_counter_id');
    }

    /**
     * @return BelongsTo<Nation, self>
     */
    public function friendlyNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'friendly_nation_id');
    }
}

<?php

namespace App\Models;

use App\Enums\SpyOperationType;
use App\Enums\SpyRoundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a single operation round within a spy campaign.
 */
class SpyRound extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => SpyRoundStatus::class,
        'op_type' => SpyOperationType::class,
        'results' => 'array',
        'notes' => 'array',
        'min_success_chance' => 'float',
    ];

    /**
     * @return BelongsTo<SpyCampaign, self>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SpyCampaign::class, 'spy_campaign_id');
    }

    /**
     * @return HasMany<SpyAssignment>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(SpyAssignment::class, 'spy_round_id');
    }
}

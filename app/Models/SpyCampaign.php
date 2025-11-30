<?php

namespace App\Models;

use App\Enums\SpyCampaignStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a multi-round spy campaign coordinating espionage assignments.
 */
class SpyCampaign extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'status' => SpyCampaignStatus::class,
    ];

    /**
     * @return HasMany<SpyCampaignAlliance>
     */
    public function alliances(): HasMany
    {
        return $this->hasMany(SpyCampaignAlliance::class);
    }

    /**
     * @return HasMany<SpyRound>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(SpyRound::class);
    }

    /**
     * @return HasManyThrough<SpyAssignment>
     */
    public function assignments(): HasManyThrough
    {
        return $this->hasManyThrough(
            SpyAssignment::class,
            SpyRound::class,
            'spy_campaign_id',
            'spy_round_id'
        );
    }

    /**
     * @return BelongsToMany<Alliance>
     */
    public function alliedAlliances(): BelongsToMany
    {
        return $this->belongsToMany(Alliance::class, 'spy_campaign_alliances')
            ->using(SpyCampaignAlliance::class)
            ->wherePivot('role', 'ally');
    }

    /**
     * @return BelongsToMany<Alliance>
     */
    public function enemyAlliances(): BelongsToMany
    {
        return $this->belongsToMany(Alliance::class, 'spy_campaign_alliances')
            ->using(SpyCampaignAlliance::class)
            ->wherePivot('role', 'enemy');
    }
}

<?php

namespace App\Models;

use App\Enums\SpyCampaignAllianceRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Links spy campaigns to alliances with a specific role.
 */
class SpyCampaignAlliance extends Pivot
{
    protected $guarded = [];

    public $incrementing = true;

    public $timestamps = true;

    protected $table = 'spy_campaign_alliances';

    protected $casts = [
        'meta' => 'array',
        'role' => SpyCampaignAllianceRole::class,
    ];

    /**
     * @return BelongsTo<SpyCampaign, self>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SpyCampaign::class, 'spy_campaign_id');
    }

    /**
     * @return BelongsTo<Alliance, self>
     */
    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class);
    }
}

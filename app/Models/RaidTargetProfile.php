<?php

namespace App\Models;

use App\Services\Economy\EconomyRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Typed raid profile for one nation: public state, stockpile baseline, daily
 * production, measured retention, and the projected stockpile value.
 */
class RaidTargetProfile extends Model
{
    use HasFactory;

    public const BASELINE_LOOT = 'loot';

    public const BASELINE_PRODUCTION_ONLY = 'production_only';

    protected $primaryKey = 'nation_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        $casts = [
            'nation_id' => 'integer',
            'alliance_id' => 'integer',
            'score' => 'float',
            'num_cities' => 'integer',
            'beige_turns' => 'integer',
            'vacation_mode_turns' => 'integer',
            'last_active' => 'immutable_datetime',
            'soldiers' => 'integer',
            'tanks' => 'integer',
            'aircraft' => 'integer',
            'ships' => 'integer',
            'missiles' => 'integer',
            'nukes' => 'integer',
            'highest_city_population' => 'integer',
            'highest_city_infra' => 'float',
            'avg_infra' => 'float',
            'defensive_wars' => 'integer',
            'offensive_wars' => 'integer',
            'baseline_at' => 'immutable_datetime',
            'baseline_attack_id' => 'integer',
            'economy_computed_at' => 'immutable_datetime',
            'retention_observed' => 'float',
            'retention_samples' => 'integer',
            'projected_value' => 'float',
            'projected_at' => 'immutable_datetime',
            'dirty_at' => 'immutable_datetime',
            'computed_at' => 'immutable_datetime',
        ];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $casts['baseline_'.$resource] = 'float';
            $casts['daily_net_'.$resource] = 'float';
        }

        return $casts;
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }

    public function allianceProfile(): BelongsTo
    {
        return $this->belongsTo(RaidAllianceProfile::class, 'alliance_id', 'alliance_id');
    }

    /**
     * Stockpile right after the baseline event.
     *
     * @return array<string, float>
     */
    public function baselineResources(): array
    {
        return $this->prefixed('baseline_');
    }

    /**
     * Signed net production per day.
     *
     * @return array<string, float>
     */
    public function dailyNet(): array
    {
        return $this->prefixed('daily_net_');
    }

    /**
     * @return array<string, float>
     */
    private function prefixed(string $prefix): array
    {
        return collect(EconomyRules::RESOURCE_KEYS)
            ->mapWithKeys(fn (string $resource): array => [$resource => (float) $this->getAttribute($prefix.$resource)])
            ->all();
    }
}

<?php

namespace App\Models;

use App\Services\Economy\EconomyRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One victory or alliance-loot attack anywhere in the world, with the stockpile
 * prediction the loser's profile held immediately before the loot was revealed.
 */
class RaidLootEvent extends Model
{
    use HasFactory;

    public const KIND_VICTORY = 'victory';

    public const KIND_ALLIANCE_LOOT = 'alliance_loot';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        $casts = [
            'war_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'winner_nation_id' => 'integer',
            'loser_nation_id' => 'integer',
            'loser_alliance_id' => 'integer',
            'loot_fraction' => 'float',
            'predicted_resources' => 'array',
            'predicted_value' => 'float',
            'revealed_value' => 'float',
            'prediction_age_hours' => 'float',
        ];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $casts[$resource] = 'float';
        }

        return $casts;
    }

    public function loser(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'loser_nation_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'winner_nation_id');
    }

    /**
     * @return array<string, float>
     */
    public function lootedResources(): array
    {
        return collect(EconomyRules::RESOURCE_KEYS)
            ->mapWithKeys(fn (string $resource): array => [$resource => (float) $this->getAttribute($resource)])
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Normalized attack evidence used to reconcile a raid prediction.
 *
 * A row is retained when an attack arrives before its declaration snapshot so
 * that event ordering cannot lose evidence. The war/attack identity is fixed;
 * later payload corrections update the normalized values and increment the
 * revision instead of creating a second contribution.
 */
final class RaidOutcomeAttack extends Model
{
    /** @var list<string> */
    public const IMMUTABLE_FIELDS = [
        'war_id',
        'attack_id',
    ];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'raid_prediction_id' => 'integer',
            'war_id' => 'integer',
            'attack_id' => 'integer',
            'attacker_nation_id' => 'integer',
            'defender_nation_id' => 'integer',
            'attack_at' => 'immutable_datetime',
            'victor' => 'integer',
            'success' => 'integer',
            'money_looted' => 'float',
            'money_stolen' => 'float',
            'money_destroyed' => 'float',
            'loot_resources' => 'array',
            'bank_loot' => 'array',
            'cost_resources' => 'array',
            'defender_cost_resources' => 'array',
            'casualties' => 'array',
            'defender_casualties' => 'array',
            'infrastructure' => 'array',
            'attacker_infrastructure' => 'array',
            'defender_infrastructure' => 'array',
            'payload' => 'array',
            'revision' => 'integer',
            'is_late' => 'boolean',
            'observed_at' => 'immutable_datetime',
        ];
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(RaidPrediction::class, 'raid_prediction_id');
    }

    public function war(): BelongsTo
    {
        return $this->belongsTo(War::class, 'war_id');
    }

    public function attack(): BelongsTo
    {
        return $this->belongsTo(WarAttack::class, 'attack_id');
    }

    protected static function booted(): void
    {
        self::updating(function (self $attack): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($attack->isDirty($field)) {
                    throw new LogicException("Raid outcome attack field [{$field}] is immutable.");
                }
            }
        });
    }
}

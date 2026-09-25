<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Immutable declaration-time inputs and the mutable result ledger for a raid.
 *
 * The declaration snapshot is intentionally kept on the tenant side. Public
 * nation and war rows can change after a declaration, while this record must
 * continue to describe exactly what was known at that point in time.
 */
final class RaidPrediction extends Model
{
    public const CAPTURE_READY = 'ready';

    /** Backwards-compatible semantic alias for a complete snapshot. */
    public const CAPTURE_CLEAN = self::CAPTURE_READY;

    public const CAPTURE_DEGRADED = 'degraded';

    public const CAPTURE_INCOMPLETE = 'incomplete';

    public const OUTCOME_OPEN = 'open';

    public const OUTCOME_WON = 'won';

    public const OUTCOME_LOST = 'lost';

    public const OUTCOME_PEACE = 'peace';

    public const OUTCOME_EXPIRED = 'expired';

    /** @var list<string> */
    public const IMMUTABLE_FIELDS = [
        'war_id',
        'attacker_nation_id',
        'target_nation_id',
        'attacker_alliance_id',
        'attacker_alliance_position',
        'declared_at',
        'captured_at',
        'observed_at',
        'capture_status',
        'capture_reason',
        'model_version',
        'attacker_snapshot',
        'target_snapshot',
        'price_snapshot',
        'context_snapshot',
        'expected_net',
        'expected_net_low',
        'expected_net_high',
        'gross_loot',
        'duration_hours',
        'win_probability',
        'victory_probability',
        'expected_attacks',
        'confidence',
        'components',
        'loot_resources',
        'cost_resources',
        'finder_rank',
        'finder_expected_net',
        'finder_shown_at',
    ];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'war_id' => 'integer',
            'attacker_nation_id' => 'integer',
            'target_nation_id' => 'integer',
            'attacker_alliance_id' => 'integer',
            'declared_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'attacker_snapshot' => 'array',
            'target_snapshot' => 'array',
            'price_snapshot' => 'array',
            'context_snapshot' => 'array',
            'expected_net' => 'float',
            'expected_net_low' => 'float',
            'expected_net_high' => 'float',
            'gross_loot' => 'float',
            'duration_hours' => 'float',
            'win_probability' => 'float',
            'victory_probability' => 'float',
            'expected_attacks' => 'integer',
            'components' => 'array',
            'loot_resources' => 'array',
            'cost_resources' => 'array',
            'finder_rank' => 'integer',
            'finder_expected_net' => 'float',
            'finder_shown_at' => 'immutable_datetime',
            'actual_attack_count' => 'integer',
            'actual_gross_loot' => 'float',
            'actual_net' => 'float',
            'actual_duration_hours' => 'float',
            'actual_components' => 'array',
            'actual_loot_resources' => 'array',
            'actual_bank_loot' => 'array',
            'actual_cost_resources' => 'array',
            'actual_losses' => 'array',
            'outcome_metadata' => 'array',
            'outcome_finalized_at' => 'immutable_datetime',
        ];
    }

    public function war(): BelongsTo
    {
        return $this->belongsTo(War::class, 'war_id');
    }

    public function attacker(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'attacker_nation_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'target_nation_id');
    }

    public function outcomeAttacks(): HasMany
    {
        return $this->hasMany(RaidOutcomeAttack::class, 'raid_prediction_id');
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->outcome_status, [
            self::OUTCOME_WON,
            self::OUTCOME_LOST,
            self::OUTCOME_PEACE,
            self::OUTCOME_EXPIRED,
        ], true);
    }

    protected static function booted(): void
    {
        self::updating(function (self $prediction): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($prediction->isDirty($field)) {
                    throw new LogicException("Raid prediction field [{$field}] is immutable.");
                }
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Raid predictions are immutable and cannot be deleted.');
        });
    }
}

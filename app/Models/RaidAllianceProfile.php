<?php

namespace App\Models;

use App\Services\Economy\EconomyRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Alliance bank estimate and counter rate used by raid valuation.
 */
class RaidAllianceProfile extends Model
{
    use HasFactory;

    protected $primaryKey = 'alliance_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        $casts = [
            'alliance_id' => 'integer',
            'alliance_score' => 'float',
            'bank_evidence_at' => 'immutable_datetime',
            'bank_attack_id' => 'integer',
            'raids_received_30d' => 'integer',
            'countered_30d' => 'integer',
            'counter_rate' => 'float',
            'computed_at' => 'immutable_datetime',
        ];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $casts['bank_'.$resource] = 'float';
        }

        return $casts;
    }

    /**
     * Estimated bank stockpile, or null when no bank evidence exists.
     *
     * @return array<string, float>|null
     */
    public function bankResources(): ?array
    {
        $bank = collect(EconomyRules::RESOURCE_KEYS)
            ->mapWithKeys(fn (string $resource): array => [$resource => $this->getAttribute('bank_'.$resource)]);

        if ($bank->every(fn (mixed $amount): bool => $amount === null)) {
            return null;
        }

        return $bank->map(fn (mixed $amount): float => (float) $amount)->all();
    }
}

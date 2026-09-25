<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Calibrated raid estimator parameters keyed by name.
 */
class RaidModelParameter extends Model
{
    use HasFactory;

    public const INTERVAL_FACTORS = 'interval_factors';

    public const BIAS_MULTIPLIERS = 'bias_multipliers';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'sample_count' => 'integer',
            'computed_at' => 'immutable_datetime',
        ];
    }
}

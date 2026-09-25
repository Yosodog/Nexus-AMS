<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The most recent finder rank a target was shown at to an attacker.
 */
class RaidFinderImpression extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attacker_nation_id' => 'integer',
            'target_nation_id' => 'integer',
            'rank' => 'integer',
            'expected_net' => 'float',
            'shown_at' => 'immutable_datetime',
        ];
    }
}

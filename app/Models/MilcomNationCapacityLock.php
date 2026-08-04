<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomNationCapacityLock extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reconciled_at' => 'datetime'];
    }

    public function friendlyNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'friendly_nation_id');
    }
}

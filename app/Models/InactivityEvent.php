<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InactivityEvent extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'episode_started_at' => 'datetime',
            'episode_ended_at' => 'datetime',
            'detected_inactive_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'dd_autoenrolled_at' => 'datetime',
            'dd_opted_out_at' => 'datetime',
            'actions_config_snapshot' => 'array',
            'meta' => 'array',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }
}

<?php

namespace App\Models;

use App\Domain\Milcom\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomIncident extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'metadata' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function war(): BelongsTo
    {
        return $this->belongsTo(War::class);
    }

    public function attackedNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'attacked_nation_id');
    }

    public function aggressorNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'aggressor_nation_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MilcomObjective::class);
    }
}

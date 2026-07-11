<?php

namespace App\Models;

use App\Enums\BlockadeReliefStatus;
use Database\Factories\BlockadeReliefRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockadeReliefRequest extends Model
{
    /** @use HasFactory<BlockadeReliefRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => BlockadeReliefStatus::class,
            'deadline_at' => 'datetime',
            'claimed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'requester_nation_id');
    }

    public function war(): BelongsTo
    {
        return $this->belongsTo(War::class, 'war_id');
    }

    public function blockadingNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'blockading_nation_id');
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'claimed_by_nation_id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

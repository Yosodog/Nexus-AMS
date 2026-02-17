<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RebuildingRequest extends Model
{
    protected $fillable = [
        'cycle_id',
        'nation_id',
        'account_id',
        'tier_id',
        'city_count_snapshot',
        'target_infrastructure_snapshot',
        'estimated_amount',
        'approved_amount',
        'status',
        'note',
        'review_note',
        'approved_by',
        'denied_by',
        'approved_at',
        'denied_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'target_infrastructure_snapshot' => 'float',
            'estimated_amount' => 'float',
            'approved_amount' => 'float',
            'approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(RebuildingTier::class, 'tier_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deniedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'denied_by');
    }
}

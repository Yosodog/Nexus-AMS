<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarAidRequest extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'nation_id',
        'account_id',
        'note',
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
        'status',
        'approved_at',
        'denied_at',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'approved_at' => 'datetime',
        'denied_at' => 'datetime',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}

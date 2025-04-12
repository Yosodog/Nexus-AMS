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

    /**
     * @return BelongsTo
     */
    public function nation(): BelongsTo {
        return $this->belongsTo(Nations::class);
    }

    /**
     * @return BelongsTo
     */
    public function account(): BelongsTo {
        return $this->belongsTo(Accounts::class);
    }

    /**
     * @return bool
     */
    public function isPending(): bool {
        return $this->status === 'pending';
    }
}

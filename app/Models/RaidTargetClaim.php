<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's short-lived claim on a raid target. Only one active claim per target
 * may exist; `pending_key` is 1 while active and null otherwise.
 */
class RaidTargetClaim extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DECLARED = 'declared';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_nation_id' => 'integer',
            'nation_id' => 'integer',
            'user_id' => 'integer',
            'pending_key' => 'integer',
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

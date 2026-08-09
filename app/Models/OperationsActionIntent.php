<?php

namespace App\Models;

use Database\Factories\OperationsActionIntentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationsActionIntent extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** @use HasFactory<OperationsActionIntentFactory> */
    use HasFactory;

    protected $fillable = [
        'token_hash',
        'actor_user_id',
        'action',
        'payload',
        'preview_fingerprint',
        'status',
        'result',
        'expires_at',
        'executed_at',
        'cancelled_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'executed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

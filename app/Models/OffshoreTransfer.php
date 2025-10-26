<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class OffshoreTransfer extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const TYPE_MAIN = 'main';
    public const TYPE_OFFSHORE = 'offshore';

    protected $fillable = [
        'user_id',
        'source_type',
        'source_offshore_id',
        'destination_type',
        'destination_offshore_id',
        'payload',
        'status',
        'message',
        'meta',
        'completed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'meta' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Offshore, self>
     */
    public function sourceOffshore(): BelongsTo
    {
        return $this->belongsTo(Offshore::class, 'source_offshore_id');
    }

    /**
     * @return BelongsTo<Offshore, self>
     */
    public function destinationOffshore(): BelongsTo
    {
        return $this->belongsTo(Offshore::class, 'destination_offshore_id');
    }

    public function markCompleted(?string $message = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'message' => $message,
            'completed_at' => Carbon::now(),
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'message' => $message,
            'completed_at' => null,
        ])->save();
    }
}

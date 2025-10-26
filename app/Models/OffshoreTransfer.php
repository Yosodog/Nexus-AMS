<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffshoreTransfer extends Model
{
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'offshore_id',
        'direction',
        'status',
        'payload',
        'response_metadata',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'response_metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    protected $attributes = [
        'payload' => '[]',
        'response_metadata' => '[]',
    ];

    public function offshore(): BelongsTo
    {
        return $this->belongsTo(Offshore::class);
    }
}

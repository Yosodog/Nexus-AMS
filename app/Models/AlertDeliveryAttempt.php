<?php

namespace App\Models;

use App\Enums\AlertAttemptStatus;
use Database\Factories\AlertDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDeliveryAttempt extends Model
{
    /** @use HasFactory<AlertDeliveryAttemptFactory> */
    use HasFactory;

    protected $attributes = [
        'adapter' => 'discord',
        'retryable' => false,
    ];

    protected $fillable = [
        'alert_delivery_batch_id',
        'attempt_number',
        'adapter',
        'status',
        'started_at',
        'finished_at',
        'latency_ms',
        'error_code',
        'error_message',
        'retryable',
        'provider_message_id',
        'provider_guild_id',
        'provider_channel_id',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'status' => AlertAttemptStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'latency_ms' => 'integer',
            'retryable' => 'boolean',
            'result' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AlertDeliveryBatch::class, 'alert_delivery_batch_id');
    }
}

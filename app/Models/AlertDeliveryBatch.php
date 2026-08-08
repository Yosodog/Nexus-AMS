<?php

namespace App\Models;

use App\Enums\AlertBatchStatus;
use App\Enums\AlertDestinationKind;
use Database\Factories\AlertDeliveryBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertDeliveryBatch extends Model
{
    /** @use HasFactory<AlertDeliveryBatchFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'schema_version' => 1,
        'is_test' => false,
    ];

    protected $fillable = [
        'alert_destination_id',
        'recipient_user_id',
        'destination_kind',
        'status',
        'template_key',
        'schema_version',
        'dedupe_key',
        'destination_snapshot',
        'is_test',
        'scheduled_at',
        'queued_at',
        'attempted_at',
        'delivered_at',
        'failed_at',
        'discord_queue_id',
        'provider_message_id',
        'failure_code',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'destination_kind' => AlertDestinationKind::class,
            'status' => AlertBatchStatus::class,
            'schema_version' => 'integer',
            'destination_snapshot' => 'array',
            'is_test' => 'boolean',
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(AlertDestination::class, 'alert_destination_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AlertDeliveryAttempt::class);
    }

    public function queueCommand(): BelongsTo
    {
        return $this->belongsTo(DiscordQueue::class, 'discord_queue_id');
    }
}

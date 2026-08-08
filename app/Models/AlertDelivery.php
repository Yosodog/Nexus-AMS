<?php

namespace App\Models;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use Database\Factories\AlertDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDelivery extends Model
{
    /** @use HasFactory<AlertDeliveryFactory> */
    use HasFactory;

    protected $attributes = [
        'delivery_mode' => 'immediate',
        'status' => 'pending',
    ];

    protected $fillable = [
        'alert_occurrence_id',
        'alert_subscription_id',
        'alert_route_id',
        'alert_delivery_batch_id',
        'alert_destination_id',
        'recipient_user_id',
        'destination_kind',
        'delivery_mode',
        'status',
        'match_key',
        'reason_code',
        'destination_snapshot',
        'scheduled_at',
        'queued_at',
        'delivered_at',
        'read_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'destination_kind' => AlertDestinationKind::class,
            'delivery_mode' => AlertDeliveryMode::class,
            'status' => AlertDeliveryStatus::class,
            'destination_snapshot' => 'array',
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(AlertOccurrence::class, 'alert_occurrence_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AlertSubscription::class, 'alert_subscription_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(AlertRoute::class, 'alert_route_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AlertDeliveryBatch::class, 'alert_delivery_batch_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(AlertDestination::class, 'alert_destination_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}

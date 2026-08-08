<?php

namespace App\Models;

use Database\Factories\AlertSubscriptionEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertSubscriptionEvent extends Model
{
    /** @use HasFactory<AlertSubscriptionEventFactory> */
    use HasFactory;

    protected $fillable = ['alert_subscription_id', 'event_key'];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AlertSubscription::class, 'alert_subscription_id');
    }
}

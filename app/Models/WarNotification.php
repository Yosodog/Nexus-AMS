<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persists outbound war-related notifications for auditing and retries.
 *
 * @property int $id
 * @property string $event_type
 * @property array $payload
 * @property string $status
 * @property \Carbon\CarbonInterface|null $sent_at
 */
class WarNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];
}

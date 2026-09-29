<?php

namespace App\Models;

use Database\Factories\WarMapAlertStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarMapAlertState extends Model
{
    /** @use HasFactory<WarMapAlertStateFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'armed' => true,
        'notification_sequence' => 0,
    ];

    protected function casts(): array
    {
        return [
            'armed' => 'boolean',
            'notification_sequence' => 'integer',
            'last_observed_at' => 'immutable_datetime',
            'last_enqueued_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function war(): BelongsTo
    {
        return $this->belongsTo(War::class);
    }
}

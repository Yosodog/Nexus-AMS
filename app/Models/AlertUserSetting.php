<?php

namespace App\Models;

use Database\Factories\AlertUserSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertUserSetting extends Model
{
    /** @use HasFactory<AlertUserSettingFactory> */
    use HasFactory;

    protected $attributes = [
        'timezone' => 'UTC',
        'default_digest_time' => '09:00:00',
        'default_digest_weekday' => 1,
        'discord_enabled' => false,
    ];

    protected $fillable = [
        'user_id',
        'timezone',
        'quiet_hours_start',
        'quiet_hours_end',
        'default_digest_time',
        'default_digest_weekday',
        'discord_enabled',
    ];

    protected function casts(): array
    {
        return [
            'default_digest_weekday' => 'integer',
            'discord_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

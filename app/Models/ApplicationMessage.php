<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $application_id
 * @property string $discord_message_id
 * @property string $discord_user_id
 * @property string $discord_username
 * @property string $discord_channel_id
 * @property string $content
 * @property bool $is_staff
 * @property \Illuminate\Support\Carbon $sent_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ApplicationMessage extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_staff' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

<?php

namespace App\Models;

use App\Enums\DiscordQueueStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Queued Discord bot command envelope.
 *
 * @property string $id
 * @property string $action
 * @property array $payload
 * @property DiscordQueueStatus $status
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $available_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class DiscordQueue extends Model
{
    use HasUuids;

    protected $table = 'discord_queue';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DiscordQueueStatus::class,
            'available_at' => 'datetime',
        ];
    }

    /**
     * Scope commands that are ready to be processed.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('status', DiscordQueueStatus::Pending->value)
            ->where('available_at', '<=', Carbon::now())
            ->orderBy('available_at')
            ->orderBy('created_at');
    }
}

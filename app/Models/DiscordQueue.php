<?php

namespace App\Models;

use App\Enums\DiscordQueueStatus;
use App\Services\Discord\DiscordQueueLeaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Queued Discord bot command envelope.
 *
 * @property string $id
 * @property string $action
 * @property string|null $dedupe_key
 * @property array $payload
 * @property DiscordQueueStatus $status
 * @property int $attempts
 * @property string|null $claim_request_id
 * @property string|null $worker_id
 * @property string|null $lease_token
 * @property Carbon|null $leased_until
 * @property array<string, mixed>|null $result
 * @property array<string, string>|null $last_error
 * @property Carbon|null $completed_at
 * @property Carbon $available_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
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
            'leased_until' => 'datetime',
            'result' => 'array',
            'last_error' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Scope commands that are ready to be processed.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('status', DiscordQueueStatus::Pending->value)
            ->where('attempts', '<', DiscordQueueLeaseService::MAX_ATTEMPTS)
            ->where('available_at', '<=', Carbon::now())
            ->orderBy('available_at')
            ->orderBy('created_at');
    }
}

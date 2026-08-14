<?php

namespace App\Models;

use App\Enums\DiscordQueueAction;
use App\Enums\DiscordQueueLane;
use App\Enums\DiscordQueueStatus;
use App\Services\Discord\DiscordQueueLeaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Queued Discord bot command envelope.
 *
 * @property string $id
 * @property string $action
 * @property string|null $connection_id
 * @property string|null $application_id
 * @property int|null $connection_generation
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

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
        'priority' => 50,
    ];

    protected $table = 'discord_queue';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        self::creating(function (self $command): void {
            $action = DiscordQueueAction::tryFrom((string) $command->action);
            $lane = $command->lane;
            if (! $action instanceof DiscordQueueAction
                || ! $lane instanceof DiscordQueueLane
                || ! $action->supportsLane($lane)) {
                throw new LogicException('Discord queue commands require a registered action on an allowed v2 lane.');
            }

            if (! in_array($command->status, [DiscordQueueStatus::Pending, DiscordQueueStatus::Processing], true)) {
                return;
            }

            if (! is_string($command->connection_id)
                || $command->connection_id === ''
                || ! is_string($command->application_id)
                || $command->application_id === ''
                || (int) $command->connection_generation < 1
                || ! is_string($command->guild_id)
                || $command->guild_id === ''
                || ! is_string($command->dedupe_scope)
                || $command->dedupe_scope === ''
                || $command->dedupe_scope === 'legacy') {
                throw new LogicException('Discord queue commands must be bound to a relay-v2 connection when created.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DiscordQueueStatus::class,
            'lane' => DiscordQueueLane::class,
            'priority' => 'integer',
            'connection_generation' => 'integer',
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
    /** @param list<string>|null $lanes */
    public function scopeAvailable(Builder $query, ?array $lanes = null): Builder
    {
        return $query
            ->where('status', DiscordQueueStatus::Pending->value)
            ->where('attempts', '<', DiscordQueueLeaseService::MAX_ATTEMPTS)
            ->where('available_at', '<=', Carbon::now())
            ->when($lanes !== null && $lanes !== [], fn (Builder $laneQuery): Builder => $laneQuery->whereIn('lane', $lanes))
            ->orderByDesc('priority')
            ->orderBy('available_at')
            ->orderBy('created_at');
    }

    public function alertDeliveryBatch(): BelongsTo
    {
        return $this->belongsTo(AlertDeliveryBatch::class);
    }
}

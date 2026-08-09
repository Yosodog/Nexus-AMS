<?php

namespace App\Models;

use App\Enums\DiscordConnectionMode;
use App\Enums\DiscordConnectionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Nexus-owned accepted Discord relay connection.
 *
 * Only public keys and routing bindings belong here. Private relay keys and
 * API credentials remain in the deployment secret store.
 *
 * @property string $id
 * @property DiscordConnectionMode $mode
 * @property DiscordConnectionState $state
 * @property string $application_id
 * @property string $guild_id
 * @property int $generation
 * @property int $protocol_version
 * @property string $relay_current_key_id
 * @property string $relay_current_public_key
 * @property string|null $relay_next_key_id
 * @property string|null $relay_next_public_key
 * @property Carbon|null $relay_next_activates_at
 * @property string|null $nexus_current_key_id
 * @property string|null $nexus_current_public_key
 * @property string|null $nexus_next_key_id
 * @property string|null $nexus_next_public_key
 * @property Carbon|null $nexus_next_activates_at
 * @property int $capability_version
 * @property array<string, mixed> $capabilities
 * @property bool $v1_reader_enabled
 * @property Carbon|null $activated_at
 * @property Carbon|null $revoked_at
 */
class DiscordConnection extends Model
{
    use HasUuids;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        self::updating(function (self $connection): void {
            if ($connection->isDirty(['id', 'application_id', 'guild_id', 'mode'])) {
                throw new LogicException('Discord connection identity is immutable.');
            }

            if ($connection->isDirty('generation')
                && (int) $connection->generation <= (int) $connection->getOriginal('generation')) {
                throw new LogicException('Discord connection generation must increase monotonically.');
            }
        });
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', DiscordConnectionState::Active->value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => DiscordConnectionMode::class,
            'state' => DiscordConnectionState::class,
            'generation' => 'integer',
            'protocol_version' => 'integer',
            'relay_next_activates_at' => 'immutable_datetime',
            'nexus_next_activates_at' => 'immutable_datetime',
            'capability_version' => 'integer',
            'capabilities' => 'array',
            'v1_reader_enabled' => 'boolean',
            'activated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}

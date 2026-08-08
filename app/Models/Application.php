<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $nation_id
 * @property string $leader_name_snapshot
 * @property string $discord_user_id
 * @property string $discord_username
 * @property string|null $discord_channel_id
 * @property string|null $discord_connection_id
 * @property int|null $discord_connection_generation
 * @property string|null $discord_application_id
 * @property string|null $discord_guild_id
 * @property int $discord_reconcile_revision
 * @property string|null $discord_reconcile_queue_id
 * @property string|null $discord_reconcile_desired_hash
 * @property array<int, string>|null $discord_reconcile_issues
 * @property ApplicationStatus $status
 * @property Carbon|null $approved_at
 * @property Carbon|null $denied_at
 * @property Carbon|null $cancelled_at
 * @property string|null $approved_by_discord_id
 * @property string|null $denied_by_discord_id
 * @property string|null $cancelled_by_discord_id
 * @property string|null $approval_request_id
 * @property string|null $denial_request_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Application extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Cast attributes to native types / enums.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'discord_connection_generation' => 'integer',
            'discord_reconcile_revision' => 'integer',
            'discord_reconcile_issues' => 'array',
            'approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ApplicationMessage::class);
    }

    public function isPending(): bool
    {
        return $this->status === ApplicationStatus::Pending;
    }
}

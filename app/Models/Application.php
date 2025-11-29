<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $nation_id
 * @property string $leader_name_snapshot
 * @property string $discord_user_id
 * @property string $discord_username
 * @property string|null $discord_channel_id
 * @property ApplicationStatus $status
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $denied_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $approved_by_discord_id
 * @property string|null $denied_by_discord_id
 * @property string|null $cancelled_by_discord_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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

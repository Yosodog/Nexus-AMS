<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Discord role created and exclusively managed by the city-tier synchronizer.
 *
 * @property int $id
 * @property int $bucket_start
 * @property int $bucket_end
 * @property string|null $discord_role_id
 * @property string|null $last_synced_queue_id
 */
class DiscordCityTierRole extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket_start' => 'integer',
            'bucket_end' => 'integer',
        ];
    }
}

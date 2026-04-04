<?php

namespace App\Models;

use App\Services\ApiDateNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

class NationAccount extends Model
{
    protected $table = 'nation_accounts';

    protected $primaryKey = 'nation_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'last_active' => 'datetime',
        'credits' => 'integer',
    ];

    public static function upsertFromEvent(array $account): ?self
    {
        if (! isset($account['id'])) {
            return null;
        }

        if (! Nation::query()->whereKey($account['id'])->exists()) {
            return null;
        }

        $payload = [
            'nation_id' => $account['id'],
            'credits' => $account['credits'] ?? null,
            'discord_id' => $account['discord_id'] ?? null,
            'last_active' => ApiDateNormalizer::normalizeTimestamp($account['last_active'] ?? null),
        ];

        return self::updateOrCreate(
            ['nation_id' => $payload['nation_id']],
            Arr::except($payload, 'nation_id')
        );
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }
}

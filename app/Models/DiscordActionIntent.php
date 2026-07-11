<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiscordActionIntent extends Model
{
    public ?string $presentedToken = null;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $intent = $this->newQuery()
            ->where('token_hash', hash('sha256', (string) $value))
            ->first();

        if ($intent) {
            $intent->presentedToken = (string) $value;
        }

        return $intent;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discordAccount(): BelongsTo
    {
        return $this->belongsTo(DiscordAccount::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'discord_action_intent_id');
    }
}

<?php

namespace App\Models;

use Database\Factories\AccountStatementExportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AccountStatementExport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const ACTIVE_KEY_VALUE = 1;

    /** @use HasFactory<AccountStatementExportFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (AccountStatementExport $export): void {
            $export->public_id ??= (string) Str::uuid();
        });

        static::saving(function (AccountStatementExport $export): void {
            $export->active_key = in_array($export->status, [
                self::STATUS_PENDING,
                self::STATUS_PROCESSING,
            ], true) ? self::ACTIVE_KEY_VALUE : null;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->expires_at !== null
            && $this->expires_at->isFuture()
            && filled($this->path);
    }

    public function hasExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}

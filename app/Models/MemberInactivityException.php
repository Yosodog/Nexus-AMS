<?php

namespace App\Models;

use App\Enums\MemberInactivityAutomation;
use App\Enums\MemberInactivityExceptionCategory;
use Carbon\CarbonInterface;
use Database\Factories\MemberInactivityExceptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberInactivityException extends Model
{
    /** @use HasFactory<MemberInactivityExceptionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => MemberInactivityExceptionCategory::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'affected_automations' => AsEnumCollection::of(MemberInactivityAutomation::class),
            'approved_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'expired_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<MemberInactivityException>  $query
     * @return Builder<MemberInactivityException>
     */
    public function scopeActiveAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->whereNull('expired_at')
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function lastReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}

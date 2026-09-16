<?php

namespace App\Models;

use App\Services\SettingService;
use Database\Factories\RecruitmentMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RecruitmentMessage extends Model
{
    /** @use HasFactory<RecruitmentMessageFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'subject',
        'message',
        'tracking_key',
        'is_active',
        'lifetime_sends',
        'lifetime_clicks',
        'current_sends',
        'current_clicks',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'lifetime_sends' => 'integer',
        'lifetime_clicks' => 'integer',
        'current_sends' => 'integer',
        'current_clicks' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (RecruitmentMessage $message): void {
            if (empty($message->tracking_key)) {
                $message->tracking_key = Str::lower(Str::random(10));
            }

            if (empty($message->type)) {
                $message->type = 'variant';
            }
        });
    }

    /**
     * @return HasMany<RecruitmentMessageClick, $this>
     */
    public function clicks(): HasMany
    {
        return $this->hasMany(RecruitmentMessageClick::class);
    }

    /**
     * @return HasMany<RecruitedNation, $this>
     */
    public function recruitedNations(): HasMany
    {
        return $this->hasMany(RecruitedNation::class);
    }

    /**
     * Scope query to recruitment message variants (excluding the follow-up template).
     *
     * @param  Builder<RecruitmentMessage>  $query
     * @return Builder<RecruitmentMessage>
     */
    public function scopeVariants(Builder $query): Builder
    {
        return $query->where('type', '!=', 'follow_up');
    }

    /**
     * Scope query to active variants in the A/B testing pool.
     *
     * @param  Builder<RecruitmentMessage>  $query
     * @return Builder<RecruitmentMessage>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the public tracking URL for this message variant.
     */
    public function getTrackingUrlAttribute(): string
    {
        return $this->trackingUrl(SettingService::getRecruitmentCurrentCohortKey());
    }

    /**
     * Build a tracking URL for a specific A/B testing cohort.
     */
    public function trackingUrl(?string $cohortKey): string
    {
        if (empty($this->tracking_key)) {
            return route('apply.show');
        }

        return route('recruitment.click', array_filter([
            'tracking_key' => $this->tracking_key,
            'cohort' => $cohortKey,
        ]));
    }

    /**
     * Calculate lifetime click-through rate percentage.
     */
    public function getLifetimeCtrAttribute(): float
    {
        if ($this->lifetime_sends <= 0) {
            return 0.0;
        }

        return round(($this->lifetime_clicks / $this->lifetime_sends) * 100, 1);
    }

    /**
     * Calculate current cohort click-through rate percentage.
     */
    public function getCurrentCtrAttribute(): float
    {
        if ($this->current_sends <= 0) {
            return 0.0;
        }

        return round(($this->current_clicks / $this->current_sends) * 100, 1);
    }
}

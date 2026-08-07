<?php

namespace App\Models;

use App\Enums\GrantDecisionReason;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class GrantApplication extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    public const PAYOUT_COLUMNS = [
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
    ];

    /**
     * @var string
     */
    public $table = 'grant_applications';

    /**
     * @var string[]
     */
    protected $fillable = [
        'grant_id',
        'program_name_snapshot',
        'program_version_snapshot',
        'nation_id',
        'account_id',
        'status',
        'decision_reason_code',
        'decision_explanation',
        'decision_internal_note',
        'reviewed_by_user_id',
        'pending_key',
        'submitted_at',
        'approved_at',
        'denied_at',
        'decided_at',
        'disbursed_at',
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'program_version_snapshot' => 'integer',
        'decision_reason_code' => GrantDecisionReason::class,
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'denied_at' => 'datetime',
        'decided_at' => 'datetime',
        'disbursed_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'decision_internal_note',
        'reviewed_by_user_id',
    ];

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grants::class, 'grant_id', 'id');
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id', 'id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function hasProgramSnapshot(): bool
    {
        return $this->program_name_snapshot !== null
            && $this->program_version_snapshot !== null;
    }

    public function memberDecisionExplanation(): ?string
    {
        if ($this->status === 'pending') {
            return null;
        }

        return $this->decision_explanation
            ?: $this->decision_reason_code?->memberGuidance();
    }

    public function submittedAtForHistory(): ?CarbonInterface
    {
        return $this->submitted_at ?? $this->created_at;
    }

    public function decidedAtForHistory(): ?CarbonInterface
    {
        return $this->decided_at ?? $this->approved_at ?? $this->denied_at;
    }

    protected static function booted(): void
    {
        static::updating(function (self $application): void {
            $snapshotFields = [
                'program_name_snapshot',
                'program_version_snapshot',
                'submitted_at',
            ];

            foreach ($snapshotFields as $field) {
                if ($application->isDirty($field)) {
                    throw new LogicException("Grant application snapshot field [{$field}] is immutable.");
                }
            }

            if ($application->getOriginal('program_version_snapshot') !== null) {
                foreach (self::PAYOUT_COLUMNS as $field) {
                    if ($application->isDirty($field)) {
                        throw new LogicException("Grant application snapshot field [{$field}] is immutable.");
                    }
                }
            }
        });
    }
}

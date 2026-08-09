<?php

namespace App\Models;

use Database\Factories\OperationsWorkEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationsWorkEvent extends Model
{
    /** @use HasFactory<OperationsWorkEventFactory> */
    use HasFactory;

    protected $fillable = [
        'coordination_id',
        'work_key',
        'occurrence_key',
        'source_type',
        'team_key',
        'event_type',
        'actor_user_id',
        'subject_user_id',
        'correlation_id',
        'idempotency_key',
        'metadata',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function coordination(): BelongsTo
    {
        return $this->belongsTo(OperationsWorkCoordination::class, 'coordination_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}

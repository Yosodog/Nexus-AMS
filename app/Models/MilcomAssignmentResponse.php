<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomAssignmentResponse extends Model
{
    protected $fillable = [
        'assignment_id',
        'user_id',
        'nation_id',
        'response',
        'reason',
        'discord_interaction_id',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MilcomAssignment::class, 'assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}

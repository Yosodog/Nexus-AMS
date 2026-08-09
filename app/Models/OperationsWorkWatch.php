<?php

namespace App\Models;

use Database\Factories\OperationsWorkWatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationsWorkWatch extends Model
{
    /** @use HasFactory<OperationsWorkWatchFactory> */
    use HasFactory;

    protected $fillable = [
        'coordination_id',
        'user_id',
        'muted_until',
        'last_notified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'muted_until' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    public function coordination(): BelongsTo
    {
        return $this->belongsTo(OperationsWorkCoordination::class, 'coordination_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

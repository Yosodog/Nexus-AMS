<?php

namespace App\Models;

use App\Enums\BackupRunStatus;
use App\Enums\BackupRunTrigger;
use Database\Factories\BackupRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled or on-demand backup attempt. `pending_key` is 1 while the run
 * is queued or running and null once it reaches a terminal status, so the
 * unique index allows only one active backup at a time.
 */
class BackupRun extends Model
{
    /** @use HasFactory<BackupRunFactory> */
    use HasFactory;

    protected $fillable = [
        'trigger',
        'status',
        'pending_key',
        'requested_by_user_id',
        'started_at',
        'finished_at',
        'backup_path',
        'backup_size_bytes',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => BackupRunTrigger::class,
            'status' => BackupRunStatus::class,
            'pending_key' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'backup_size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @param Builder<BackupRun> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [BackupRunStatus::Queued->value, BackupRunStatus::Running->value]);
    }

    /** @param Builder<BackupRun> $query */
    public function scopeWithStatus(Builder $query, BackupRunStatus $status): void
    {
        $query->where('status', $status->value);
    }
}

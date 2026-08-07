<?php

namespace App\Models;

use App\Enums\ScheduledTaskRunStatus;
use Database\Factories\ScheduledTaskRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledTaskRun extends Model
{
    /** @use HasFactory<ScheduledTaskRunFactory> */
    use HasFactory;

    protected $fillable = [
        'task_identifier',
        'task_mutex_hash',
        'status',
        'scheduled_at',
        'started_at',
        'finished_at',
        'duration_ms',
        'exit_code',
        'hostname',
        'correlation_id',
        'exception_class',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScheduledTaskRunStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'exit_code' => 'integer',
        ];
    }
}

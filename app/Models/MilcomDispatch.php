<?php

namespace App\Models;

use App\Domain\Milcom\Enums\DispatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomDispatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DispatchStatus::class,
            'payload_snapshot' => 'array',
            'errors' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'archived_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MilcomOperation::class, 'operation_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MilcomObjective::class, 'objective_id');
    }
}

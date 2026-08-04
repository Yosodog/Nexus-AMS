<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomAssignmentDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MilcomOperation::class, 'operation_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MilcomAssignment::class, 'assignment_id');
    }
}

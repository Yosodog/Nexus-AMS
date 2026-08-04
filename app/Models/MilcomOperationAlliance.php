<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomOperationAlliance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['included' => 'boolean', 'metadata' => 'array'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MilcomOperation::class, 'operation_id');
    }

    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class);
    }
}

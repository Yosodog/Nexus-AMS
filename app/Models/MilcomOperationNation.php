<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilcomOperationNation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['included' => 'boolean'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MilcomOperation::class, 'operation_id');
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}

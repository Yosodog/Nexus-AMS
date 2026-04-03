<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthCircleEnrollment extends Model
{
    protected $fillable = [
        'nation_id',
        'previous_tax_id',
        'suspended',
        'suspended_at',
        'suspended_reason',
        'enrolled_at',
    ];

    protected function casts(): array
    {
        return [
            'suspended' => 'bool',
            'suspended_at' => 'datetime',
            'enrolled_at' => 'datetime',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}

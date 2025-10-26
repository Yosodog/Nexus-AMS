<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffshoreGuardrail extends Model
{
    public const RESOURCES = [
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
        'credits',
    ];

    protected $fillable = [
        'offshore_id',
        'resource',
        'minimum_amount',
    ];

    protected $casts = [
        'minimum_amount' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<Offshore, self>
     */
    public function offshore(): BelongsTo
    {
        return $this->belongsTo(Offshore::class);
    }

    public function appliesTo(string $resource): bool
    {
        return $this->resource === $resource;
    }
}

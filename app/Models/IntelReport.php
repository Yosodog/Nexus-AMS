<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelReport extends Model
{
    protected $fillable = [
        'nation_id',
        'nation_name',
        'user_id',
        'money',
        'coal',
        'oil',
        'uranium',
        'lead',
        'iron',
        'bauxite',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
        'operation_cost',
        'spies_captured',
        'was_detected',
        'source',
        'raw_text',
        'hash',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'was_detected' => 'boolean',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

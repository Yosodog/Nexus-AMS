<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrowthCircleEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'nation_id',
        'account_id',
        'previous_tax_id',
        'enrolled_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'previous_tax_id' => 'integer',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(GrowthCircleDistribution::class, 'enrollment_id');
    }
}

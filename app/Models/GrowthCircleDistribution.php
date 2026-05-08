<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthCircleDistribution extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'nation_id',
        'account_id',
        'enrollment_id',
        'food',
        'uranium',
        'cycle_date',
    ];

    protected $casts = [
        'food' => 'float',
        'uranium' => 'float',
        'cycle_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(GrowthCircleEnrollment::class, 'enrollment_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollGrade extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollGradeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'weekly_amount',
        'is_enabled',
        'created_by',
    ];

    protected $casts = [
        'weekly_amount' => 'decimal:2',
        'is_enabled' => 'boolean',
    ];

    /**
     * @return HasMany<PayrollMember>
     */
    public function members(): HasMany
    {
        return $this->hasMany(PayrollMember::class);
    }

    /**
     * @return BelongsTo<User>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

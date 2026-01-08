<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollMember extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'nation_id',
        'payroll_grade_id',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<PayrollGrade>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(PayrollGrade::class, 'payroll_grade_id');
    }

    /**
     * @return BelongsTo<Nation>
     */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }

    /**
     * @return BelongsTo<User>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

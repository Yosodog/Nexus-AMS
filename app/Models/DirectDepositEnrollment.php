<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectDepositEnrollment extends Model
{
    use HasFactory;

    /**
     * @var string[]
     */
    protected $fillable = [
        'nation_id',
        'account_id',
        'previous_tax_id',
        'enrolled_at',
    ];

    /**
     * @var string[]
     */
    protected $dates = ['enrolled_at'];

    /**
     * @var string[]
     */
    protected $casts = [
        'enrolled_at' => 'datetime',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

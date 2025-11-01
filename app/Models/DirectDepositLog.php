<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectDepositLog extends Model
{
    use HasFactory;

    /**
     * @var string[]
     */
    protected $fillable = [
        'nation_id',
        'account_id',
        'bank_record_id',
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
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function bankRecord(): BelongsTo
    {
        return $this->belongsTo(BankRecord::class);
    }
}

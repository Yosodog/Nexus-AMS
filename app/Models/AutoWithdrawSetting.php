<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoWithdrawSetting extends Model
{
    protected $fillable = [
        'nation_id',
        'account_id',
        'resource',
        'threshold',
        'withdraw_amount',
        'enabled',
        'last_withdraw_at',
    ];

    protected $casts = [
        'enabled' => 'bool',
        'last_withdraw_at' => 'datetime',
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

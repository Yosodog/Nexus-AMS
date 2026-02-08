<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'nation_id',
        'account_id',
        'resource',
        'amount',
        'adjustment_percent',
        'final_price',
        'money_paid',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'adjustment_percent' => 'decimal:2',
        'final_price' => 'decimal:4',
        'money_paid' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<User, MarketTransaction>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Nation, MarketTransaction>
     */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /**
     * @return BelongsTo<Account, MarketTransaction>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

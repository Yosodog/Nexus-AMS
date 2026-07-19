<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LotteryPurchase extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'idempotency_key',
        'lottery_drawing_id',
        'user_id',
        'nation_id',
        'account_id',
        'quantity',
        'total_cost',
        'jackpot_contribution',
        'manual_transaction_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'total_cost' => 'decimal:2',
            'jackpot_contribution' => 'decimal:2',
        ];
    }

    public function drawing(): BelongsTo
    {
        return $this->belongsTo(LotteryDrawing::class, 'lottery_drawing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function manualTransaction(): BelongsTo
    {
        return $this->belongsTo(ManualTransaction::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(LotteryTicket::class);
    }
}

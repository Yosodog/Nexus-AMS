<?php

namespace App\Models;

use Database\Factories\LotteryTicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LotteryTicket extends Model
{
    /** @use HasFactory<LotteryTicketFactory> */
    use HasFactory;

    protected $fillable = [
        'lottery_drawing_id',
        'user_id',
        'nation_id',
        'account_id',
        'code',
        'price_paid',
        'jackpot_contribution',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_paid' => 'decimal:2',
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
}

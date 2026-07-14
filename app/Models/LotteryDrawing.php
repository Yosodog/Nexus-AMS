<?php

namespace App\Models;

use Database\Factories\LotteryDrawingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LotteryDrawing extends Model
{
    public const STATUS_DRAWN = 'drawn';

    public const STATUS_OPEN = 'open';

    /** @use HasFactory<LotteryDrawingFactory> */
    use HasFactory;

    protected $fillable = [
        'starts_at',
        'ends_at',
        'status',
        'ticket_price',
        'ticket_count',
        'allocation_seed',
        'next_ticket_sequence',
        'rollover_amount',
        'jackpot_amount',
        'winning_code',
        'winning_ticket_id',
        'drawn_at',
    ];

    protected $hidden = [
        'allocation_seed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'ticket_price' => 'decimal:2',
            'ticket_count' => 'integer',
            'next_ticket_sequence' => 'integer',
            'rollover_amount' => 'decimal:2',
            'jackpot_amount' => 'decimal:2',
            'drawn_at' => 'immutable_datetime',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(LotteryTicket::class);
    }

    public function winningTicket(): BelongsTo
    {
        return $this->belongsTo(LotteryTicket::class, 'winning_ticket_id');
    }
}

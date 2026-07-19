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
        'sales_enabled',
        'ticket_price',
        'jackpot_basis_points',
        'jackpot_contribution_per_ticket',
        'max_tickets_per_purchase',
        'max_tickets_per_nation',
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
            'sales_enabled' => 'boolean',
            'ticket_price' => 'decimal:2',
            'jackpot_basis_points' => 'integer',
            'jackpot_contribution_per_ticket' => 'decimal:2',
            'max_tickets_per_purchase' => 'integer',
            'max_tickets_per_nation' => 'integer',
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

    public function purchases(): HasMany
    {
        return $this->hasMany(LotteryPurchase::class);
    }

    public function winningTicket(): BelongsTo
    {
        return $this->belongsTo(LotteryTicket::class, 'winning_ticket_id');
    }
}

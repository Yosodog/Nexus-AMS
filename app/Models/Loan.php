<?php

namespace App\Models;

use App\Services\LoanService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    /**
     * @var string
     */
    public $table = 'loans';

    /**
     * @var string[]
     */
    protected $fillable = [
        'nation_id',
        'account_id',
        'amount',
        'remaining_balance',
        'weekly_interest_paid',
        'scheduled_weekly_payment',
        'past_due_amount',
        'accrued_interest_due',
        'interest_rate',
        'term_weeks',
        'status',
        'approved_at',
        'next_due_date',
    ];

    protected $casts = [
        'next_due_date' => 'datetime',
        'amount' => 'float',
        'remaining_balance' => 'float',
        'weekly_interest_paid' => 'float',
        'scheduled_weekly_payment' => 'float',
        'past_due_amount' => 'float',
        'accrued_interest_due' => 'float',
        'interest_rate' => 'float',
    ];

    /**
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nation::class);
    }

    /**
     * @return BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function getNextPaymentDue(): float
    {
        $loanService = app(LoanService::class);

        return $loanService->calculateCurrentAmountDue($this);
    }

    /**
     * @return HasMany
     */
    public function payments()
    {
        return $this->hasMany(LoanPayment::class, 'loan_id');
    }
}

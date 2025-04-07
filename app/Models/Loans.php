<?php

namespace App\Models;

use App\Services\LoanService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loans extends Model
{
    /**
     * @var string
     */
    public $table = "loans";
    /**
     * @var string[]
     */
    protected $fillable = [
        'nation_id',
        'account_id',
        'amount',
        'remaining_balance',
        'interest_rate',
        'term_weeks',
        'status',
        'approved_at',
        'next_due_date'
    ];

    protected $casts = [
        'next_due_date' => 'datetime'
    ];

    /**
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nations::class);
    }

    /**
     * @return BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Accounts::class);
    }

    /**
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * @return float
     */
    public function getNextPaymentDue(): float
    {
        $loanService = app(LoanService::class); // Resolve the service
        $weeklyPayment = $loanService->calculateWeeklyPayment($this);

        // Get total early payments made since last due date
        $earlyPayments = $this->payments()
            ->where('payment_date', '>=', $this->next_due_date->subDays(7))
            ->sum('amount');

        // If early payments cover the weekly payment, the next payment is $0
        return max(0, $weeklyPayment - $earlyPayments);
    }

    /**
     * @return HasMany
     */
    public function payments()
    {
        return $this->hasMany(LoanPayments::class, 'loan_id');
    }

}

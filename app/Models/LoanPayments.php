<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanPayments extends Model
{
    public $table = "loan_payments";

    protected $fillable = [
        'loan_id',
        'account_id',
        'amount',
        'principal_paid',
        'interest_paid',
        'payment_date',
    ];

    /**
     * @return BelongsTo
     */
    public function loan()
    {
        return $this->belongsTo(Loans::class, 'loan_id');
    }

    /**
     * @return BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Accounts::class);
    }
}

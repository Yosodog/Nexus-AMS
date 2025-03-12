<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'interest_rate',
        'term_weeks',
        'status',
        'approved_at'
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
}

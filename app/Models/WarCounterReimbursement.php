<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarCounterReimbursement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gasoline' => 'float',
        'munitions' => 'float',
        'steel' => 'float',
        'aluminum' => 'float',
        'resources_cost' => 'float',
        'unit_loss_cost' => 'float',
        'infra_loss_cost' => 'float',
        'total_cost' => 'float',
        'meta' => 'array',
    ];

    /**
     * @return BelongsTo<WarCounter, self>
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(WarCounter::class, 'war_counter_id');
    }

    /**
     * @return BelongsTo<Nation, self>
     */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /**
     * @return BelongsTo<Account, self>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<ManualTransaction, self>
     */
    public function manualTransaction(): BelongsTo
    {
        return $this->belongsTo(ManualTransaction::class);
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function reimbursedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reimbursed_by');
    }
}

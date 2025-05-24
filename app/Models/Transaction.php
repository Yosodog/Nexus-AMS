<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{

    public $table = "transactions";

    /**
     * @return BelongsTo
     */
    public function toAccount()
    {
        return $this->belongsTo(Account::class, "to_account_id", "id");
    }

    /**
     * @return BelongsTo
     */
    public function fromAccount()
    {
        return $this->belongsTo(Account::class, "from_account_id", "id");
    }

    /**
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nation::class, "nation_id", "id");
    }

    /**
     * @return void
     */
    public function setSent(): void
    {
        $this->is_pending = false;
        $this->save();
    }

    /**
     * @return bool
     */
    public function isNationWithdrawal(): bool
    {
        return $this->transaction_type === 'withdrawal' && is_null($this->to_account_id);
    }

    /**
     * @return bool
     */
    public function isRefunded(): bool
    {
        return !is_null($this->refunded_at);
    }

}

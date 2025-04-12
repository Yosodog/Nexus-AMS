<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositRequest extends Model
{

    public $fillable = ["account_id", "deposit_code"];

    /**
     * @return BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Account::class, "account_id", "id");
    }

}

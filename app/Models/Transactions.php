<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transactions extends Model
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
        return $this->belongsTo(Nations::class, "nation_id", "id");
    }

    /**
     * @return void
     */
    public function setSent(): void
    {
        $this->is_pending = false;
        $this->save();
    }

}

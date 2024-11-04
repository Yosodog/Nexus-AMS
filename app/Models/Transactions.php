<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transactions extends Model
{
    public $table = "transactions";

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function toAccount()
    {
        return $this->belongsTo(Accounts::class, "to_account_id", "id");
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fromAccount()
    {
        return $this->belongsTo(Accounts::class, "from_account_id", "id");
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nations::class, "nation_id", "id");
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Accounts extends Model
{
    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nations::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeigeAlertAlliance extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<Alliance, self>
     */
    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class);
    }
}

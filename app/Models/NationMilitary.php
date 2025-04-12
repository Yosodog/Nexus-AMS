<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationMilitary extends Model
{
    protected $table = "nation_military";

    protected $guarded = [];

    /**
     * Relationship to the Nation model.
     * Each NationMilitary record belongs to a single Nation.
     *
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nation::class);
    }
}

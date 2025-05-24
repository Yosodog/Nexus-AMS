<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class NationMilitary extends Model
{
    use SoftDeletes;

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

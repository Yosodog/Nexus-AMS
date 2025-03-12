<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationResources extends Model
{
    protected $table = "nation_resources";

    protected $guarded = [];

    /**
     * Relationship to the Nation model.
     * Each NationResources record belongs to a single Nation.
     *
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nations::class);
    }
}

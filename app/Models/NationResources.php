<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NationResources extends Model
{
    protected $table = "nation_resources";

    protected $guarded = [];

    /**
     * Relationship to the Nation model.
     * Each NationResources record belongs to a single Nation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nations::class);
    }
}

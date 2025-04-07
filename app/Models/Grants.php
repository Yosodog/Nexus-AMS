<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grants extends Model
{
    /**
     * @var string
     */
    public $table = "grants";

    /**
     * @var string[]
     */
    protected $fillable = [
        'name',
        'money',
        'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead',
        'gasoline', 'munitions', 'steel', 'aluminum', 'food',
        'validation_rules',
        'is_enabled',
        'is_one_time',
        'description'
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'validation_rules' => 'array',
        'is_enabled' => 'boolean',
        'is_one_time' => 'boolean',
    ];

    /**
     * @return HasMany
     */
    public function applications(): HasMany
    {
        return $this->hasMany(GrantApplications::class, "grant_id", "id");
    }

}

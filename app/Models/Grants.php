<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Grants extends Model
{
    use SoftDeletes;

    /**
     * @var string
     */
    public $table = 'grants';

    /**
     * @var string[]
     */
    protected $fillable = [
        'name',
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
        'validation_rules',
        'is_enabled',
        'is_one_time',
        'description',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'validation_rules' => 'array',
        'is_enabled' => 'boolean',
        'is_one_time' => 'boolean',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(GrantApplication::class, 'grant_id', 'id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CityGrant extends Model
{

    /**
     * @var string[]
     */
    protected $fillable = [
        'name',
        'description',
        'enabled',
        'grant_amount',
        'city_number',
        'requirements',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'enabled' => 'boolean',
        'requirements' => 'array',
    ];


}

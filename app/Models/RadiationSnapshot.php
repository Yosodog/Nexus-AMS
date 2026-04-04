<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RadiationSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot_at' => 'datetime',
            'global' => 'float',
            'north_america' => 'float',
            'south_america' => 'float',
            'europe' => 'float',
            'africa' => 'float',
            'asia' => 'float',
            'australia' => 'float',
            'antarctica' => 'float',
        ];
    }
}

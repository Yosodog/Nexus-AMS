<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DirectDepositTaxBracket extends Model
{
    use HasFactory;

    protected $fillable = [
        'city_number',
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
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TradePrice extends Model
{
    use HasFactory;

    protected $table = 'trade_prices';

    protected $fillable = [
        'date',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gas',
        'munitions',
        'steel',
        'aluminum',
        'food',
        'credits',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}

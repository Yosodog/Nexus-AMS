<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawLimit extends Model
{
    protected $fillable = [
        'resource',
        'daily_limit',
    ];
}

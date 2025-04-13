<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Treaty extends Model
{
    protected $fillable = [
        'pw_id',
        'pw_date',
        'turns_left',
        'alliance1_id',
        'alliance2_id',
        'type',
    ];
}

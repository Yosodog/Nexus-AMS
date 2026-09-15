<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RaidAttackObservation extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'occurred_at' => 'immutable_datetime', 'payload' => 'array'];
    }
}

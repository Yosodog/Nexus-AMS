<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RaidNationObservation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'payload' => 'array', 'provenance_war_ids' => 'array'];
    }
}

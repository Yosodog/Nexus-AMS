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
        return [
            'current_key' => 'integer',
            'observed_at' => 'immutable_datetime',
            'valid_from' => 'immutable_datetime',
            'confirmed_through' => 'immutable_datetime',
            'payload' => 'array',
            'provenance_war_ids' => 'array',
        ];
    }
}

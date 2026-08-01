<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxImportCheckpoint extends Model
{
    protected $fillable = [
        'alliance_id',
        'last_scanned_id',
    ];
}

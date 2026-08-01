<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxImportRejection extends Model
{
    protected $fillable = [
        'alliance_id',
        'tax_record_id',
        'reason',
        'raw_timestamp',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}

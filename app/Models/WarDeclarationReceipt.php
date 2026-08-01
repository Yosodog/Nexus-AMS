<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarDeclarationReceipt extends Model
{
    protected $primaryKey = 'war_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];
}

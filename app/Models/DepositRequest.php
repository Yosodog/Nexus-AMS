<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositRequest extends Model
{
    public $fillable = ["account_id", "deposit_code"];
}

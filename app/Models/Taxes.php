<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Taxes extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'id', 'date', 'sender_id', 'receiver_id', 'receiver_type',
        'money', 'coal', 'oil', 'uranium', 'iron', 'bauxite',
        'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food',
        'tax_id',
    ];

    /**
     * @var string
     */
    protected $primaryKey = 'id';
    /**
     * @var bool
     */
    public $incrementing = false;
}

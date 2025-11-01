<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CityGrantRequest extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'city_number',
        'grant_amount',
        'nation_id',
        'account_id',
        'status',
        'approved_at',
        'denied_at',
    ];

    /**
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nation::class, 'nation_id', 'id');
    }
}

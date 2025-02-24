<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nations::class, "nation_id", "id");
    }

}

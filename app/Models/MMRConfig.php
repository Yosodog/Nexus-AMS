<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MMRConfig extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'nation_id',
        'account_id',
        'enabled',
        'coal_pct',
        'oil_pct',
        'uranium_pct',
        'iron_pct',
        'bauxite_pct',
        'lead_pct',
        'gasoline_pct',
        'munitions_pct',
        'steel_pct',
        'aluminum_pct',
        'food_pct',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'enabled' => 'boolean',
        'coal_pct' => 'float',
        'oil_pct' => 'float',
        'uranium_pct' => 'float',
        'iron_pct' => 'float',
        'bauxite_pct' => 'float',
        'lead_pct' => 'float',
        'gasoline_pct' => 'float',
        'munitions_pct' => 'float',
        'steel_pct' => 'float',
        'aluminum_pct' => 'float',
        'food_pct' => 'float',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}

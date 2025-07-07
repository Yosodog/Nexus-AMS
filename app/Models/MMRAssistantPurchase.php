<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class MMRAssistantPurchase extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'account_id',
        'total_spent',
        'coal', 'coal_ppu',
        'oil', 'oil_ppu',
        'uranium', 'uranium_ppu',
        'iron', 'iron_ppu',
        'bauxite', 'bauxite_ppu',
        'lead', 'lead_ppu',
        'gasoline', 'gasoline_ppu',
        'munitions', 'munitions_ppu',
        'steel', 'steel_ppu',
        'aluminum', 'aluminum_ppu',
        'food', 'food_ppu',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'total_spent' => 'float',
        'coal' => 'float', 'coal_ppu' => 'float',
        'oil' => 'float', 'oil_ppu' => 'float',
        'uranium' => 'float', 'uranium_ppu' => 'float',
        'iron' => 'float', 'iron_ppu' => 'float',
        'bauxite' => 'float', 'bauxite_ppu' => 'float',
        'lead' => 'float', 'lead_ppu' => 'float',
        'gasoline' => 'float', 'gasoline_ppu' => 'float',
        'munitions' => 'float', 'munitions_ppu' => 'float',
        'steel' => 'float', 'steel_ppu' => 'float',
        'aluminum' => 'float', 'aluminum_ppu' => 'float',
        'food' => 'float', 'food_ppu' => 'float',
    ];

    public $table = "mmr_assistant_purchases";

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}

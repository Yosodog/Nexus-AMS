<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthCircleDistribution extends Model
{
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<int, string>
     */
    public static function distributionResourceKeys(): array
    {
        return ['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'food'];
    }

    /**
     * @return array<string, string>
     */
    public static function distributionResourceLabels(): array
    {
        return [
            'coal' => 'Coal',
            'oil' => 'Oil',
            'uranium' => 'Uranium',
            'iron' => 'Iron',
            'bauxite' => 'Bauxite',
            'lead' => 'Lead',
            'food' => 'Food',
        ];
    }

    protected $fillable = [
        'nation_id',
        'account_id',
        'enrollment_id',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'food',
        'cycle_date',
    ];

    protected $casts = [
        'coal' => 'float',
        'oil' => 'float',
        'uranium' => 'float',
        'iron' => 'float',
        'bauxite' => 'float',
        'lead' => 'float',
        'food' => 'float',
        'cycle_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(GrowthCircleEnrollment::class, 'enrollment_id');
    }
}

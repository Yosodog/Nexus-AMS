<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DirectDepositTaxBracket extends Model
{
    use HasFactory;

    public const MIN_TAX_RATE = 0.00;

    public const MAX_TAX_RATE = 100.00;

    protected $fillable = [
        'city_number',
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return collect($this->rateFields())
            ->mapWithKeys(fn (string $field): array => [$field => 'decimal:2'])
            ->prepend('integer', 'city_number')
            ->all();
    }

    public static function normalizeTaxRate(float $rate): float
    {
        return round(min(max($rate, self::MIN_TAX_RATE), self::MAX_TAX_RATE), 2);
    }

    /**
     * @return array<int, string>
     */
    public static function rateFields(): array
    {
        return [
            'money',
            'coal',
            'oil',
            'uranium',
            'iron',
            'bauxite',
            'lead',
            'gasoline',
            'munitions',
            'steel',
            'aluminum',
            'food',
        ];
    }
}

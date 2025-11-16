<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property CarbonInterface $date
 * @property string $direction
 * @property string $category
 * @property string|null $description
 * @property int|null $nation_id
 * @property int|null $account_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property float $money
 * @property float $coal
 * @property float $oil
 * @property float $uranium
 * @property float $iron
 * @property float $bauxite
 * @property float $lead
 * @property float $gasoline
 * @property float $munitions
 * @property float $steel
 * @property float $aluminum
 * @property float $food
 * @property array|null $meta
 */
class AllianceFinanceEntry extends Model
{
    public const DIRECTION_INCOME = 'income';

    public const DIRECTION_EXPENSE = 'expense';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'money' => 'float',
        'coal' => 'float',
        'oil' => 'float',
        'uranium' => 'float',
        'iron' => 'float',
        'bauxite' => 'float',
        'lead' => 'float',
        'gasoline' => 'float',
        'munitions' => 'float',
        'steel' => 'float',
        'aluminum' => 'float',
        'food' => 'float',
        'meta' => 'array',
    ];

    /**
     * @return BelongsTo<Account, self>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Nation, self>
     */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /**
     * @return MorphTo<Model, self>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine if the entry represents income.
     */
    public function isIncome(): bool
    {
        return $this->direction === self::DIRECTION_INCOME;
    }

    /**
     * Determine if the entry represents an expense.
     */
    public function isExpense(): bool
    {
        return $this->direction === self::DIRECTION_EXPENSE;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Offshore extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'alliance_id',
        'priority',
        'enabled',
        'min_money',
        'min_resources',
        'api_key',
        'api_mutation_key',
    ];

    protected $hidden = [
        'api_key',
        'api_mutation_key',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'min_resources' => 'array',
        'api_key' => 'encrypted',
        'api_mutation_key' => 'encrypted',
    ];

    protected $attributes = [
        'min_money' => 0,
        'min_resources' => [],
    ];

    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(OffshoreTransfer::class);
    }

    protected function minMoney(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $value === null ? 0.0 : (float) $value,
            set: fn($value) => $value === null
                ? 0
                : number_format((float) $value, 2, '.', ''),
        );
    }

    protected function minResources(): Attribute
    {
        return Attribute::make(
            get: fn($value) => Collection::make($value ?? [])
                ->map(fn($amount) => (float) $amount)
                ->toArray(),
            set: fn($value) => Collection::make($value ?? [])
                ->map(fn($amount) => (float) $amount)
                ->toArray(),
        );
    }

    public function resourceMinimum(string $resource): float
    {
        return (float) ($this->min_resources[$resource] ?? 0);
    }
}

<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads a 0/1 column as a PHP boolean while keeping the stored and serialized form an integer.
 *
 * The Politics & War API delivers real booleans but MySQL hands back 0/1, and Eloquent treats
 * `true` and `1` as different values, so an uncast flag column looks dirty on every API update.
 * Storing the integer form keeps that comparison stable, and serializing the integer form keeps
 * JSON output (for example the members API consumed by the Discord bot) exactly as before.
 *
 * @implements CastsAttributes<bool|null, bool|int|string|null>
 */
final class IntegerFlagCast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : (int) (bool) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : (int) (bool) $value;
    }
}

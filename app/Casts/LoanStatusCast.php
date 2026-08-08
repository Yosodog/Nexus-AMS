<?php

namespace App\Casts;

use App\Enums\LoanStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<LoanStatus|string, LoanStatus|string> */
final class LoanStatusCast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): LoanStatus|string
    {
        if ($value instanceof LoanStatus) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Loan status values must be strings or LoanStatus cases.');
        }

        return LoanStatus::fromStoredValue($value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof LoanStatus) {
            return $value->value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Loan status values must be strings or LoanStatus cases.');
        }

        $status = LoanStatus::tryFrom($value);

        if ($status === null) {
            throw new InvalidArgumentException("Unknown loan status [{$value}] cannot be assigned.");
        }

        return $status->value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof LoanStatus) {
            return $value->value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Loan status values must be strings or LoanStatus cases.');
        }

        return $value;
    }
}

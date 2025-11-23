<?php

namespace App\Nel;

use App\Exceptions\UserErrorException;

class MathNelHelper
{
    /**
     * @return array<string, callable>
     */
    public function bindings(): array
    {
        return [
            'math.floor_to_multiple' => [$this, 'floorToMultiple'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function functionNames(): array
    {
        return array_keys($this->bindings());
    }

    /**
     * @throws UserErrorException
     */
    public function floorToMultiple(NelEvaluationContext $context, float|int $value, float|int $multiple): int|float
    {
        if ($multiple == 0) { // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators.DisallowedEqualOperator
            throw new UserErrorException('floor_to_multiple requires a non-zero multiple.');
        }

        return floor($value / $multiple) * $multiple;
    }
}

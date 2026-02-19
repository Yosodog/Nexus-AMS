<?php

namespace App\Nel;

use App\Nel\Ast\BinaryOpNode;
use App\Nel\Ast\ExpressionNode;
use App\Nel\Ast\FunctionCallNode;
use App\Nel\Ast\IdentifierNode;
use App\Nel\Ast\LiteralNode;
use App\Nel\Ast\UnaryOpNode;
use App\Nel\Exception\NelEvaluationException;
use App\Nel\Exception\NelUnknownFunctionException;

final class NelEvaluator
{
    public function __construct(private readonly VariableResolver $resolver = new DefaultVariableResolver) {}

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, callable>  $helpers
     */
    public function evaluate(ExpressionNode $node, array $variables = [], array $helpers = []): mixed
    {
        $context = new NelEvaluationContext($variables, $helpers, $this->resolver);

        return $this->evaluateNode($node, $context);
    }

    /**
     * @return bool|int|float|string|null
     */
    private function evaluateNode(ExpressionNode $node, NelEvaluationContext $context): mixed
    {
        if ($node instanceof LiteralNode) {
            return $node->value;
        }

        if ($node instanceof IdentifierNode) {
            return $context->resolver->resolve($context->variables, $node->segments);
        }

        if ($node instanceof UnaryOpNode) {
            return $this->evaluateUnary($node, $context);
        }

        if ($node instanceof BinaryOpNode) {
            return $this->evaluateBinary($node, $context);
        }

        if ($node instanceof FunctionCallNode) {
            return $this->evaluateFunction($node, $context);
        }

        throw new NelEvaluationException('Unsupported expression node encountered.');
    }

    /**
     * @return bool|int|float|string|null
     */
    private function evaluateUnary(UnaryOpNode $node, NelEvaluationContext $context): mixed
    {
        $value = $this->evaluateNode($node->operand, $context);

        if ($node->operator === '!') {
            return ! $this->isTruthy($value);
        }

        if ($node->operator === '-') {
            if (! $this->isNumericValue($value)) {
                throw new NelEvaluationException('Unary minus requires a numeric value.');
            }

            return -1 * $this->toNumeric($value);
        }

        throw new NelEvaluationException('Unknown unary operator '.$node->operator);
    }

    /**
     * @return bool|int|float|string|null
     */
    private function evaluateBinary(BinaryOpNode $node, NelEvaluationContext $context): mixed
    {
        $operator = $node->operator;

        if ($operator === '||') {
            $left = $this->evaluateNode($node->left, $context);
            if ($this->isTruthy($left)) {
                return true;
            }

            $right = $this->evaluateNode($node->right, $context);

            return $this->isTruthy($right);
        }

        if ($operator === '&&') {
            $left = $this->evaluateNode($node->left, $context);
            if (! $this->isTruthy($left)) {
                return false;
            }

            $right = $this->evaluateNode($node->right, $context);

            return $this->isTruthy($right);
        }

        $left = $this->evaluateNode($node->left, $context);
        $right = $this->evaluateNode($node->right, $context);

        return match ($operator) {
            '+', '-', '*', '/', '%' => $this->evaluateArithmetic($operator, $left, $right),
            '<', '<=', '>', '>=' => $this->evaluateComparison($operator, $left, $right),
            '==', '!=' => $this->evaluateEquality($operator, $left, $right),
            default => throw new NelEvaluationException('Unknown binary operator '.$operator),
        };
    }

    private function evaluateArithmetic(string $operator, mixed $left, mixed $right): int|float
    {
        if (! $this->isNumericValue($left) || ! $this->isNumericValue($right)) {
            throw new NelEvaluationException('Arithmetic operators require numeric operands.');
        }

        $leftNumber = $this->toNumeric($left);
        $rightNumber = $this->toNumeric($right);

        return match ($operator) {
            '+' => $leftNumber + $rightNumber,
            '-' => $leftNumber - $rightNumber,
            '*' => $leftNumber * $rightNumber,
            '/' => $this->divide($leftNumber, $rightNumber),
            '%' => $this->modulo($leftNumber, $rightNumber),
            default => throw new NelEvaluationException('Unsupported arithmetic operator '.$operator),
        };
    }

    private function divide(int|float $left, int|float $right): float|int
    {
        if ($right == 0) { // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators.DisallowedEqualOperator
            throw new NelEvaluationException('Division by zero.');
        }

        return $left / $right;
    }

    private function modulo(int|float $left, int|float $right): int|float
    {
        if ($right == 0) { // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators.DisallowedEqualOperator
            throw new NelEvaluationException('Division by zero.');
        }

        return $left % $right;
    }

    private function evaluateComparison(string $operator, mixed $left, mixed $right): bool
    {
        if (! $this->isNumericValue($left) || ! $this->isNumericValue($right)) {
            throw new NelEvaluationException('Comparison operators require numeric operands.');
        }

        $leftNumber = $this->toNumeric($left);
        $rightNumber = $this->toNumeric($right);

        return match ($operator) {
            '<' => $leftNumber < $rightNumber,
            '<=' => $leftNumber <= $rightNumber,
            '>' => $leftNumber > $rightNumber,
            '>=' => $leftNumber >= $rightNumber,
            default => throw new NelEvaluationException('Unsupported comparison operator '.$operator),
        };
    }

    private function evaluateEquality(string $operator, mixed $left, mixed $right): bool
    {
        if ($this->isNumericValue($left) && $this->isNumericValue($right)) {
            $result = $this->toNumeric($left) === $this->toNumeric($right);
        } else {
            $result = $left === $right;
        }

        return $operator === '==' ? $result : ! $result;
    }

    /**
     * @return bool|int|float|string|null
     */
    private function evaluateFunction(FunctionCallNode $node, NelEvaluationContext $context): mixed
    {
        if (! array_key_exists($node->name, $context->helpers)) {
            throw new NelUnknownFunctionException('Unknown function '.$node->name);
        }

        $arguments = [];
        foreach ($node->arguments as $argument) {
            $arguments[] = $this->evaluateNode($argument, $context);
        }

        $callable = $context->helpers[$node->name];

        return $callable($context, ...$arguments);
    }

    private function isNumericValue(mixed $value): bool
    {
        return is_int($value)
            || is_float($value)
            || (is_string($value) && is_numeric($value));
    }

    private function toNumeric(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return (float) $value;
    }

    private function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }

        if ($value === 0 || $value === 0.0) {
            return false;
        }

        if ($value === '') {
            return false;
        }

        if ($value === []) {
            return false;
        }

        return true;
    }
}

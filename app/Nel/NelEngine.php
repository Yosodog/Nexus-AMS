<?php

namespace App\Nel;

use App\Nel\Ast\ExpressionNode;

final class NelEngine
{
    public function __construct(
        private readonly NelParser $parser,
        private readonly NelEvaluator $evaluator,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, callable>  $helpers
     */
    public function evaluate(string $expression, array $variables = [], array $helpers = []): mixed
    {
        return $this->evaluateParsed($this->parse($expression), $variables, $helpers);
    }

    public function parse(string $expression): ExpressionNode
    {
        return $this->parser->parse($expression);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, callable>  $helpers
     */
    public function evaluateParsed(ExpressionNode $ast, array $variables = [], array $helpers = []): mixed
    {
        return $this->evaluator->evaluate($ast, $variables, $helpers);
    }
}

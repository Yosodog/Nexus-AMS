<?php

namespace App\Nel;

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
        $ast = $this->parser->parse($expression);

        return $this->evaluator->evaluate($ast, $variables, $helpers);
    }
}

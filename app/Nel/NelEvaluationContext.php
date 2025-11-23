<?php

namespace App\Nel;

final class NelEvaluationContext
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, callable>  $helpers
     */
    public function __construct(
        public readonly array $variables,
        public readonly array $helpers,
        public readonly VariableResolver $resolver
    ) {}
}

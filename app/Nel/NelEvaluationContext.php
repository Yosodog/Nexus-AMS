<?php

namespace App\Nel;

final readonly class NelEvaluationContext
{
    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, callable>  $helpers
     */
    public function __construct(
        public array $variables,
        public array $helpers,
        public VariableResolver $resolver
    ) {}
}

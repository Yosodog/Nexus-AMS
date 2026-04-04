<?php

namespace App\Nel\Ast;

final readonly class FunctionCallNode implements ExpressionNode
{
    /**
     * @param  list<ExpressionNode>  $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments
    ) {}
}

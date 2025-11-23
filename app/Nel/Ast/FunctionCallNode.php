<?php

namespace App\Nel\Ast;

final class FunctionCallNode implements ExpressionNode
{
    /**
     * @param  list<ExpressionNode>  $arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments
    ) {}
}

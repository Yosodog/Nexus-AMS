<?php

namespace App\Nel\Ast;

final readonly class UnaryOpNode implements ExpressionNode
{
    public function __construct(
        public string $operator,
        public ExpressionNode $operand
    ) {}
}

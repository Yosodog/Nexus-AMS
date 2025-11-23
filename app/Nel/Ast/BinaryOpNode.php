<?php

namespace App\Nel\Ast;

final class BinaryOpNode implements ExpressionNode
{
    public function __construct(
        public readonly ExpressionNode $left,
        public readonly string $operator,
        public readonly ExpressionNode $right
    ) {}
}

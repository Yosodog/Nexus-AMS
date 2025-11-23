<?php

namespace App\Nel\Ast;

final class IdentifierNode implements ExpressionNode
{
    /**
     * @param  list<string>  $segments
     */
    public function __construct(public readonly array $segments) {}
}

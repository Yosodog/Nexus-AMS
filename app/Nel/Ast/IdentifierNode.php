<?php

namespace App\Nel\Ast;

final readonly class IdentifierNode implements ExpressionNode
{
    /**
     * @param  list<string>  $segments
     */
    public function __construct(public array $segments) {}
}

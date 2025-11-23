<?php

namespace App\Nel;

final class TokenType
{
    public const IDENTIFIER = 'IDENTIFIER';

    public const DOT = 'DOT';

    public const NUMBER = 'NUMBER';

    public const STRING = 'STRING';

    public const BOOLEAN = 'BOOLEAN';

    public const NULL = 'NULL';

    public const LPAREN = 'LPAREN';

    public const RPAREN = 'RPAREN';

    public const COMMA = 'COMMA';

    public const PLUS = 'PLUS';

    public const MINUS = 'MINUS';

    public const STAR = 'STAR';

    public const SLASH = 'SLASH';

    public const PERCENT = 'PERCENT';

    public const BANG = 'BANG';

    public const AND = 'AND';

    public const OR = 'OR';

    public const EQUAL_EQUAL = 'EQUAL_EQUAL';

    public const BANG_EQUAL = 'BANG_EQUAL';

    public const LESS = 'LESS';

    public const LESS_EQUAL = 'LESS_EQUAL';

    public const GREATER = 'GREATER';

    public const GREATER_EQUAL = 'GREATER_EQUAL';

    public const END = 'END';
}

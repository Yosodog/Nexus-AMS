<?php

namespace Database\Migrations\Support\AuditRuleMigration;

enum TokenType: string
{
    case Identifier = 'identifier';
    case Dot = 'dot';
    case Number = 'number';
    case String = 'string';
    case Boolean = 'boolean';
    case Null = 'null';
    case LeftParenthesis = 'left_parenthesis';
    case RightParenthesis = 'right_parenthesis';
    case Comma = 'comma';
    case Plus = 'plus';
    case Minus = 'minus';
    case Star = 'star';
    case Slash = 'slash';
    case Percent = 'percent';
    case Bang = 'bang';
    case And = 'and';
    case Or = 'or';
    case Equal = 'equal';
    case NotEqual = 'not_equal';
    case Less = 'less';
    case LessOrEqual = 'less_or_equal';
    case Greater = 'greater';
    case GreaterOrEqual = 'greater_or_equal';
    case End = 'end';
}

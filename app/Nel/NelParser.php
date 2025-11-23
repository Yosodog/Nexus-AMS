<?php

namespace App\Nel;

use App\Nel\Ast\BinaryOpNode;
use App\Nel\Ast\ExpressionNode;
use App\Nel\Ast\FunctionCallNode;
use App\Nel\Ast\IdentifierNode;
use App\Nel\Ast\LiteralNode;
use App\Nel\Ast\UnaryOpNode;
use App\Nel\Exception\NelSyntaxException;

final class NelParser
{
    /** @var Token[] */
    private array $tokens = [];

    private int $position = 0;

    public function __construct(private readonly NelTokenizer $tokenizer) {}

    public function parse(string $expression): ExpressionNode
    {
        $this->tokens = $this->tokenizer->tokenize($expression);
        $this->position = 0;

        $node = $this->parseExpression();
        $this->expect(TokenType::END);

        return $node;
    }

    private function parseExpression(): ExpressionNode
    {
        $node = $this->parseAndExpression();

        while ($this->match(TokenType::OR)) {
            $operator = '||';
            $right = $this->parseAndExpression();
            $node = new BinaryOpNode($node, $operator, $right);
        }

        return $node;
    }

    private function parseAndExpression(): ExpressionNode
    {
        $node = $this->parseComparisonExpression();

        while ($this->match(TokenType::AND)) {
            $operator = '&&';
            $right = $this->parseComparisonExpression();
            $node = new BinaryOpNode($node, $operator, $right);
        }

        return $node;
    }

    private function parseComparisonExpression(): ExpressionNode
    {
        $node = $this->parseAdditiveExpression();

        while (true) {
            if ($this->match(TokenType::LESS)) {
                $node = new BinaryOpNode($node, '<', $this->parseAdditiveExpression());

                continue;
            }

            if ($this->match(TokenType::LESS_EQUAL)) {
                $node = new BinaryOpNode($node, '<=', $this->parseAdditiveExpression());

                continue;
            }

            if ($this->match(TokenType::GREATER)) {
                $node = new BinaryOpNode($node, '>', $this->parseAdditiveExpression());

                continue;
            }

            if ($this->match(TokenType::GREATER_EQUAL)) {
                $node = new BinaryOpNode($node, '>=', $this->parseAdditiveExpression());

                continue;
            }

            if ($this->match(TokenType::EQUAL_EQUAL)) {
                $node = new BinaryOpNode($node, '==', $this->parseAdditiveExpression());

                continue;
            }

            if ($this->match(TokenType::BANG_EQUAL)) {
                $node = new BinaryOpNode($node, '!=', $this->parseAdditiveExpression());

                continue;
            }

            break;
        }

        return $node;
    }

    private function parseAdditiveExpression(): ExpressionNode
    {
        $node = $this->parseMultiplicativeExpression();

        while (true) {
            if ($this->match(TokenType::PLUS)) {
                $node = new BinaryOpNode($node, '+', $this->parseMultiplicativeExpression());

                continue;
            }

            if ($this->match(TokenType::MINUS)) {
                $node = new BinaryOpNode($node, '-', $this->parseMultiplicativeExpression());

                continue;
            }

            break;
        }

        return $node;
    }

    private function parseMultiplicativeExpression(): ExpressionNode
    {
        $node = $this->parseUnaryExpression();

        while (true) {
            if ($this->match(TokenType::STAR)) {
                $node = new BinaryOpNode($node, '*', $this->parseUnaryExpression());

                continue;
            }

            if ($this->match(TokenType::SLASH)) {
                $node = new BinaryOpNode($node, '/', $this->parseUnaryExpression());

                continue;
            }

            if ($this->match(TokenType::PERCENT)) {
                $node = new BinaryOpNode($node, '%', $this->parseUnaryExpression());

                continue;
            }

            break;
        }

        return $node;
    }

    private function parseUnaryExpression(): ExpressionNode
    {
        if ($this->match(TokenType::BANG)) {
            return new UnaryOpNode('!', $this->parseUnaryExpression());
        }

        if ($this->match(TokenType::MINUS)) {
            return new UnaryOpNode('-', $this->parseUnaryExpression());
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): ExpressionNode
    {
        if ($this->match(TokenType::NUMBER)) {
            return new LiteralNode($this->previous()->value);
        }

        if ($this->match(TokenType::STRING)) {
            return new LiteralNode($this->previous()->value);
        }

        if ($this->match(TokenType::BOOLEAN)) {
            return new LiteralNode($this->previous()->value);
        }

        if ($this->match(TokenType::NULL)) {
            return new LiteralNode(null);
        }

        if ($this->match(TokenType::IDENTIFIER)) {
            $token = $this->previous();
            if ($this->check(TokenType::LPAREN)) {
                return $this->finishFunctionCall($token);
            }

            return $this->finishIdentifierPath($token);
        }

        if ($this->match(TokenType::LPAREN)) {
            $expr = $this->parseExpression();
            $this->consume(TokenType::RPAREN, 'Expected ")" to close group.');

            return $expr;
        }

        $token = $this->peek();
        throw new NelSyntaxException('Unexpected token '.$token->type.' at position '.$token->position);
    }

    private function finishIdentifierPath(Token $firstIdentifier): IdentifierNode
    {
        $segments = [$firstIdentifier->value];

        while ($this->match(TokenType::DOT)) {
            $segmentToken = $this->consume(TokenType::IDENTIFIER, 'Expected identifier after "."');
            $segments[] = $segmentToken->value;
        }

        return new IdentifierNode($segments);
    }

    private function finishFunctionCall(Token $nameToken): FunctionCallNode
    {
        $this->consume(TokenType::LPAREN, 'Expected "(" after function name');
        $arguments = [];

        if (! $this->check(TokenType::RPAREN)) {
            do {
                $arguments[] = $this->parseExpression();
            } while ($this->match(TokenType::COMMA));
        }

        $this->consume(TokenType::RPAREN, 'Expected ")" after function arguments');

        return new FunctionCallNode($nameToken->value, $arguments);
    }

    private function match(string $type): bool
    {
        if ($this->check($type)) {
            $this->advance();

            return true;
        }

        return false;
    }

    private function consume(string $type, string $message): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        $token = $this->peek();
        throw new NelSyntaxException($message.' Found '.$token->type.' at position '.$token->position);
    }

    private function expect(string $type): void
    {
        if (! $this->check($type)) {
            $token = $this->peek();
            throw new NelSyntaxException('Expected '.$type.' but found '.$token->type.' at position '.$token->position);
        }
    }

    private function check(string $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function advance(): Token
    {
        if (! $this->isAtEnd()) {
            $this->position++;
        }

        return $this->previous();
    }

    private function isAtEnd(): bool
    {
        return $this->peek()->type === TokenType::END;
    }

    private function peek(): Token
    {
        return $this->tokens[$this->position];
    }

    private function previous(): Token
    {
        return $this->tokens[$this->position - 1];
    }
}

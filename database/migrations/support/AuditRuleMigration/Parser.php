<?php

namespace Database\Migrations\Support\AuditRuleMigration;

use Database\Migrations\Support\AuditRuleMigration\Ast\BinaryOpNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\ExpressionNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\FunctionCallNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\IdentifierNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\LiteralNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\UnaryOpNode;

final class Parser
{
    /** @var list<Token> */
    private array $tokens = [];

    private int $position = 0;

    public function __construct(private readonly Tokenizer $tokenizer = new Tokenizer) {}

    public function parse(string $expression): ExpressionNode
    {
        $this->tokens = $this->tokenizer->tokenize($expression);
        $this->position = 0;
        $node = $this->parseOrExpression();
        $this->expect(TokenType::End);

        return $node;
    }

    private function parseOrExpression(): ExpressionNode
    {
        $node = $this->parseAndExpression();

        while ($this->match(TokenType::Or)) {
            $node = new BinaryOpNode($node, '||', $this->parseAndExpression());
        }

        return $node;
    }

    private function parseAndExpression(): ExpressionNode
    {
        $node = $this->parseComparisonExpression();

        while ($this->match(TokenType::And)) {
            $node = new BinaryOpNode($node, '&&', $this->parseComparisonExpression());
        }

        return $node;
    }

    private function parseComparisonExpression(): ExpressionNode
    {
        $node = $this->parseAdditiveExpression();
        $operators = [
            TokenType::Less->value => '<',
            TokenType::LessOrEqual->value => '<=',
            TokenType::Greater->value => '>',
            TokenType::GreaterOrEqual->value => '>=',
            TokenType::Equal->value => '==',
            TokenType::NotEqual->value => '!=',
        ];

        while (isset($operators[$this->peek()->type->value])) {
            $operator = $operators[$this->advance()->type->value];
            $node = new BinaryOpNode($node, $operator, $this->parseAdditiveExpression());
        }

        return $node;
    }

    private function parseAdditiveExpression(): ExpressionNode
    {
        $node = $this->parseMultiplicativeExpression();

        while ($this->check(TokenType::Plus) || $this->check(TokenType::Minus)) {
            $operator = $this->advance()->type === TokenType::Plus ? '+' : '-';
            $node = new BinaryOpNode($node, $operator, $this->parseMultiplicativeExpression());
        }

        return $node;
    }

    private function parseMultiplicativeExpression(): ExpressionNode
    {
        $node = $this->parseUnaryExpression();

        while ($this->check(TokenType::Star) || $this->check(TokenType::Slash) || $this->check(TokenType::Percent)) {
            $operator = match ($this->advance()->type) {
                TokenType::Star => '*',
                TokenType::Slash => '/',
                TokenType::Percent => '%',
                default => throw new SyntaxException('Unexpected multiplicative operator.'),
            };
            $node = new BinaryOpNode($node, $operator, $this->parseUnaryExpression());
        }

        return $node;
    }

    private function parseUnaryExpression(): ExpressionNode
    {
        if ($this->match(TokenType::Bang)) {
            return new UnaryOpNode('!', $this->parseUnaryExpression());
        }

        if ($this->match(TokenType::Minus)) {
            return new UnaryOpNode('-', $this->parseUnaryExpression());
        }

        return $this->parsePrimaryExpression();
    }

    private function parsePrimaryExpression(): ExpressionNode
    {
        if ($this->match(TokenType::Number, TokenType::String, TokenType::Boolean, TokenType::Null)) {
            return new LiteralNode($this->previous()->value);
        }

        if ($this->match(TokenType::Identifier)) {
            $segments = $this->collectIdentifierSegments($this->previous());

            if ($this->check(TokenType::LeftParenthesis)) {
                return $this->finishFunctionCall($segments);
            }

            return new IdentifierNode($segments);
        }

        if ($this->match(TokenType::LeftParenthesis)) {
            $node = $this->parseOrExpression();
            $this->consume(TokenType::RightParenthesis, 'Expected ")" to close group.');

            return $node;
        }

        $token = $this->peek();

        throw new SyntaxException('Unexpected token '.$token->type->value.' at position '.$token->position.'.');
    }

    /**
     * @return list<string>
     */
    private function collectIdentifierSegments(Token $firstIdentifier): array
    {
        $segments = [(string) $firstIdentifier->value];

        while ($this->match(TokenType::Dot)) {
            $segments[] = (string) $this->consume(
                TokenType::Identifier,
                'Expected identifier after ".".',
            )->value;
        }

        return $segments;
    }

    /**
     * @param  list<string>  $segments
     */
    private function finishFunctionCall(array $segments): FunctionCallNode
    {
        $this->consume(TokenType::LeftParenthesis, 'Expected "(" after function name.');
        $arguments = [];

        if (! $this->check(TokenType::RightParenthesis)) {
            do {
                $arguments[] = $this->parseOrExpression();
            } while ($this->match(TokenType::Comma));
        }

        $this->consume(TokenType::RightParenthesis, 'Expected ")" after function arguments.');

        return new FunctionCallNode(implode('.', $segments), $arguments);
    }

    private function match(TokenType ...$types): bool
    {
        foreach ($types as $type) {
            if ($this->check($type)) {
                $this->advance();

                return true;
            }
        }

        return false;
    }

    private function consume(TokenType $type, string $message): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        $token = $this->peek();

        throw new SyntaxException($message.' Found '.$token->type->value.' at position '.$token->position.'.');
    }

    private function expect(TokenType $type): void
    {
        if (! $this->check($type)) {
            $token = $this->peek();

            throw new SyntaxException(
                'Expected '.$type->value.' but found '.$token->type->value.' at position '.$token->position.'.',
            );
        }
    }

    private function check(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function advance(): Token
    {
        if ($this->peek()->type !== TokenType::End) {
            $this->position++;
        }

        return $this->previous();
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

<?php

namespace Tests\Unit\Nel;

use App\Nel\Exception\NelSyntaxException;
use App\Nel\NelTokenizer;
use App\Nel\TokenType;
use PHPUnit\Framework\TestCase;

class NelTokenizerTest extends TestCase
{
    public function test_tokenizes_simple_expression(): void
    {
        $tokenizer = new NelTokenizer;
        $tokens = $tokenizer->tokenize('nation.score > 500');

        $types = array_map(static fn ($token) => $token->type, $tokens);

        $this->assertSame(
            [
                TokenType::IDENTIFIER,
                TokenType::DOT,
                TokenType::IDENTIFIER,
                TokenType::GREATER,
                TokenType::NUMBER,
                TokenType::END,
            ],
            $types
        );

        $this->assertSame(500, $tokens[4]->value);
    }

    public function test_tokenizes_arithmetic_and_strings(): void
    {
        $tokenizer = new NelTokenizer;
        $tokens = $tokenizer->tokenize("nation.military.soldiers + 1000 - 'infantry'");

        $this->assertSame(TokenType::STRING, $tokens[8]->type);
        $this->assertSame('infantry', $tokens[8]->value);
    }

    public function test_ignores_whitespace(): void
    {
        $tokenizer = new NelTokenizer;
        $tokens = $tokenizer->tokenize("  nation.score\t!=\n500  ");

        $this->assertSame(TokenType::IDENTIFIER, $tokens[0]->type);
        $this->assertSame(TokenType::BANG_EQUAL, $tokens[3]->type);
        $this->assertSame(TokenType::NUMBER, $tokens[4]->type);
    }

    public function test_throws_on_invalid_character(): void
    {
        $this->expectException(NelSyntaxException::class);

        $tokenizer = new NelTokenizer;
        $tokenizer->tokenize('nation.score $ 500');
    }
}

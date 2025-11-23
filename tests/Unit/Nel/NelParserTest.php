<?php

namespace Tests\Unit\Nel;

use App\Nel\Ast\BinaryOpNode;
use App\Nel\Ast\FunctionCallNode;
use App\Nel\Ast\IdentifierNode;
use App\Nel\Ast\LiteralNode;
use App\Nel\Ast\UnaryOpNode;
use App\Nel\Exception\NelSyntaxException;
use App\Nel\NelParser;
use App\Nel\NelTokenizer;
use PHPUnit\Framework\TestCase;

class NelParserTest extends TestCase
{
    private NelParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NelParser(new NelTokenizer);
    }

    public function test_parses_boolean_expression(): void
    {
        $ast = $this->parser->parse('nation.score > 500 && nation.military.soldiers < 6000');

        $this->assertInstanceOf(BinaryOpNode::class, $ast);
        $this->assertSame('&&', $ast->operator);
        $this->assertInstanceOf(BinaryOpNode::class, $ast->left);
        $this->assertInstanceOf(BinaryOpNode::class, $ast->right);
    }

    public function test_respects_operator_precedence(): void
    {
        $ast = $this->parser->parse('1 + 2 * 3');

        $this->assertInstanceOf(BinaryOpNode::class, $ast);
        $this->assertSame('+', $ast->operator);
        $this->assertInstanceOf(LiteralNode::class, $ast->left);
        $this->assertInstanceOf(BinaryOpNode::class, $ast->right);
        $this->assertSame('*', $ast->right->operator);
    }

    public function test_parentheses_override_precedence(): void
    {
        $ast = $this->parser->parse('(1 + 2) * 3');

        $this->assertInstanceOf(BinaryOpNode::class, $ast);
        $this->assertSame('*', $ast->operator);
        $this->assertInstanceOf(BinaryOpNode::class, $ast->left);
        $this->assertSame('+', $ast->left->operator);
    }

    public function test_parses_unary_and_function_calls(): void
    {
        $ast = $this->parser->parse('!(nation.score >= 1000)');
        $this->assertInstanceOf(UnaryOpNode::class, $ast);
        $this->assertSame('!', $ast->operator);

        $functionAst = $this->parser->parse('funcName(1, 2, 3 + 4)');
        $this->assertInstanceOf(FunctionCallNode::class, $functionAst);
        $this->assertCount(3, $functionAst->arguments);
        $this->assertInstanceOf(BinaryOpNode::class, $functionAst->arguments[2]);
    }

    public function test_parses_identifier_paths(): void
    {
        $ast = $this->parser->parse('nation.military.soldiers');

        $this->assertInstanceOf(IdentifierNode::class, $ast);
        $this->assertSame(['nation', 'military', 'soldiers'], $ast->segments);
    }

    public function test_throws_on_invalid_syntax(): void
    {
        $this->expectException(NelSyntaxException::class);
        $this->parser->parse('(1 + 2');
    }

    public function test_throws_on_dangling_operator(): void
    {
        $this->expectException(NelSyntaxException::class);
        $this->parser->parse('1 +');
    }
}

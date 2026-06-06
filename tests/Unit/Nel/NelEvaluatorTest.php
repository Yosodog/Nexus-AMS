<?php

namespace Tests\Unit\Nel;

use App\Nel\Exception\NelUnknownVariableException;
use App\Nel\NelEngine;
use App\Nel\NelEvaluator;
use App\Nel\NelParser;
use App\Nel\NelTokenizer;
use PHPUnit\Framework\TestCase;

class NelEvaluatorTest extends TestCase
{
    private NelEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new NelEngine(new NelParser(new NelTokenizer), new NelEvaluator);
    }

    public function test_evaluates_literals_and_booleans(): void
    {
        $this->assertTrue($this->engine->evaluate('true'));
        $this->assertFalse($this->engine->evaluate('false'));
        $this->assertNull($this->engine->evaluate('null'));
        $this->assertSame(123, $this->engine->evaluate('123'));
        $this->assertSame('foo', $this->engine->evaluate('"foo"'));
    }

    public function test_evaluates_comparisons_and_boolean_logic(): void
    {
        $this->assertTrue($this->engine->evaluate('1 < 2'));
        $this->assertTrue($this->engine->evaluate('2 >= 2'));
        $this->assertTrue($this->engine->evaluate('1 == 1'));
        $this->assertTrue($this->engine->evaluate('1 != 2'));
        $this->assertFalse($this->engine->evaluate('true && false'));
        $this->assertTrue($this->engine->evaluate('true || false'));
        $this->assertTrue($this->engine->evaluate('!false'));
    }

    public function test_resolves_variables(): void
    {
        $variables = [
            'nation' => [
                'score' => 1200,
                'military' => [
                    'soldiers' => 50000,
                ],
            ],
        ];

        $this->assertTrue($this->engine->evaluate('nation.score > 1000', $variables));
        $this->assertTrue($this->engine->evaluate('nation.military.soldiers < 60000', $variables));
    }

    public function test_resolves_public_dto_properties(): void
    {
        $variables = [
            'nation' => (object) [
                'score' => 1200,
                'military' => (object) [
                    'soldiers' => 50000,
                ],
            ],
        ];

        $this->assertTrue($this->engine->evaluate('nation.score > 1000', $variables));
        $this->assertTrue($this->engine->evaluate('nation.military.soldiers < 60000', $variables));
    }

    public function test_does_not_call_object_getters_or_methods_when_resolving_variables(): void
    {
        $probe = new class
        {
            public bool $getterCalled = false;

            public bool $methodCalled = false;

            public function getSecret(): int
            {
                $this->getterCalled = true;

                return 1;
            }

            public function destroy(): int
            {
                $this->methodCalled = true;

                return 1;
            }
        };

        foreach (['probe.secret == 1', 'probe.destroy == 1'] as $expression) {
            try {
                $this->engine->evaluate($expression, ['probe' => $probe]);
                $this->fail('NEL variable resolution should not execute object methods.');
            } catch (NelUnknownVariableException $exception) {
                $this->assertStringStartsWith('Unknown variable path: probe.', $exception->getMessage());
            }
        }

        $this->assertFalse($probe->getterCalled);
        $this->assertFalse($probe->methodCalled);
    }

    public function test_calls_helpers(): void
    {
        $helpers = [
            'double' => static fn ($ctx, $value) => $value * 2,
        ];

        $this->assertTrue($this->engine->evaluate('double(2) == 4', [], $helpers));
    }
}

<?php

namespace Tests\Unit\Federation;

use App\Domain\Federation\Support\StrictJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StrictJsonTest extends TestCase
{
    public function test_it_decodes_a_strict_object(): void
    {
        $this->assertSame(['a' => 1, 'nested' => ['b' => true]], StrictJson::decodeObject(
            '{"a":1,"nested":{"b":true}}'
        ));
    }

    public function test_it_rejects_duplicate_properties_at_any_depth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StrictJson::decodeObject('{"a":1,"nested":{"x":1,"\\u0078":2}}');
    }

    public function test_it_rejects_non_object_roots_and_trailing_data(): void
    {
        foreach (['[]', '{"a":1} null'] as $json) {
            try {
                StrictJson::decodeObject($json);
                $this->fail('Invalid JSON shape was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(2, $this->numberOfAssertionsPerformed());
    }
}

<?php

namespace Tests\Unit\Milcom;

use App\Domain\Milcom\Enums\PriorityTier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PriorityTierTest extends TestCase
{
    #[Test]
    #[DataProvider('tierContractProvider')]
    public function tiers_have_fixed_priority_and_plan_depth_contracts(
        PriorityTier $tier,
        int $order,
        array $depth,
    ): void {
        $this->assertSame($order, $tier->order());
        $this->assertSame($depth, $tier->defaultDepth());
    }

    public static function tierContractProvider(): array
    {
        return [
            'critical' => [PriorityTier::Critical, 0, ['desired' => 3, 'minimum' => 2]],
            'high' => [PriorityTier::High, 1, ['desired' => 2, 'minimum' => 1]],
            'standard' => [PriorityTier::Standard, 2, ['desired' => 1, 'minimum' => 1]],
            'hold' => [PriorityTier::Hold, 3, ['desired' => 0, 'minimum' => 0]],
        ];
    }
}

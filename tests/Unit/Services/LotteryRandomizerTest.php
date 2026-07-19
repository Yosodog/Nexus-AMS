<?php

namespace Tests\Unit\Services;

use App\Services\LotteryRandomizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LotteryRandomizerTest extends TestCase
{
    public function test_seeded_permutation_allocates_the_entire_code_space_without_duplicates(): void
    {
        $randomizer = new LotteryRandomizer;
        $codes = $randomizer->ticketCodesForRange(
            str_repeat('0123456789abcdef', 4),
            0,
            LotteryRandomizer::CODE_SPACE_SIZE,
        );

        $this->assertCount(LotteryRandomizer::CODE_SPACE_SIZE, $codes);
        $this->assertCount(LotteryRandomizer::CODE_SPACE_SIZE, array_unique($codes));
        $this->assertSame([], array_filter(
            $codes,
            fn (string $code): bool => preg_match('/^[0-9A-Z]{3}$/', $code) !== 1,
        ));
    }

    public function test_same_seed_and_sequence_always_return_the_same_code(): void
    {
        $randomizer = new LotteryRandomizer;
        $seed = str_repeat('abcdef0123456789', 4);

        $this->assertSame(
            $randomizer->ticketCodeForSequence($seed, 12345),
            $randomizer->ticketCodeForSequence($seed, 12345),
        );
        $this->assertNotSame(
            $randomizer->ticketCodeForSequence($seed, 12345),
            $randomizer->ticketCodeForSequence(str_repeat('1', 64), 12345),
        );
    }

    public function test_range_cannot_extend_beyond_the_code_space(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LotteryRandomizer)->ticketCodesForRange(
            str_repeat('a', 64),
            LotteryRandomizer::CODE_SPACE_SIZE - 1,
            2,
        );
    }
}

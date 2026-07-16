<?php

namespace Tests\Unit\Factories;

use App\Models\LotteryDrawing;
use App\Models\LotteryTicket;
use Tests\TestCase;

class LotteryFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        fake()->unique(true);

        parent::tearDown();
    }

    public function test_ticket_factory_generates_unique_valid_codes_for_one_drawing(): void
    {
        $codes = LotteryTicket::factory()
            ->count(1000)
            ->make([
                'lottery_drawing_id' => 1,
                'user_id' => 1,
                'nation_id' => 1,
                'account_id' => 1,
            ])
            ->pluck('code');

        $this->assertCount(1000, $codes);
        $this->assertCount(1000, $codes->unique());
        $this->assertTrue($codes->every(
            fn (string $code): bool => preg_match('/^[0-9A-Z]{3}$/', $code) === 1,
        ));
    }

    public function test_drawn_factory_populates_a_consistent_terminal_state(): void
    {
        $drawing = LotteryDrawing::factory()->drawn()->make();

        $this->assertSame(LotteryDrawing::STATUS_DRAWN, $drawing->status);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{3}$/', $drawing->winning_code);
        $this->assertTrue($drawing->drawn_at->equalTo($drawing->ends_at));
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class LotteryScheduleTest extends TestCase
{
    public function test_lottery_drawer_checks_for_overdue_drawings_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => is_string($event->command)
                && str_contains($event->command, 'lottery:draw'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertSame('UTC', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }
}

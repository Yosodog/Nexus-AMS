<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EconomyScheduleTest extends TestCase
{
    #[Test]
    public function economy_inputs_and_calculations_run_in_dependency_order_with_locks(): void
    {
        $events = collect(app(Schedule::class)->events());
        $expected = [
            'market-prices:refresh' => '12 * * * *',
            'economy-context:refresh' => '16 * * * *',
            'pw:sync-radiation' => '18 * * * *',
            'profitability:refresh' => '20 * * * *',
            'build-recommendations:refresh' => '25 */2 * * *',
        ];

        foreach ($expected as $command => $expression) {
            $event = $events->first(fn (Event $event): bool => is_string($event->command)
                && str_contains($event->command, $command));

            $this->assertInstanceOf(Event::class, $event, "Missing scheduled command {$command}.");
            $this->assertSame($expression, $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
        }
    }
}

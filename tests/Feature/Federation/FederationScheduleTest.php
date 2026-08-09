<?php

namespace Tests\Feature\Federation;

use App\Jobs\ExpireFederationResourcesJob;
use App\Jobs\PruneFederationMessagesJob;
use App\Jobs\ReconcileFederationLinksJob;
use App\Jobs\SweepFederationOutboxJob;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Tests\TestCase;

class FederationScheduleTest extends TestCase
{
    public function test_federation_recovery_and_maintenance_jobs_are_scheduled_with_cluster_locks(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertFederationSchedule(
            $events,
            SweepFederationOutboxJob::class,
            '* * * * *',
            1,
        );
        $this->assertFederationSchedule(
            $events,
            ReconcileFederationLinksJob::class,
            '*/15 * * * *',
            14,
        );
        $this->assertFederationSchedule(
            $events,
            ExpireFederationResourcesJob::class,
            '*/5 * * * *',
            4,
        );
        $this->assertFederationSchedule(
            $events,
            PruneFederationMessagesJob::class,
            '30 2 * * *',
            120,
        );
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function assertFederationSchedule(
        $events,
        string $job,
        string $expression,
        int $overlapMinutes,
    ): void {
        $matches = $events->filter(fn (Event $event): bool => $event->description === $job);

        $this->assertCount(1, $matches, "Expected one schedule for {$job}.");
        $event = $matches->first();

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame($expression, $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame($overlapMinutes, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }
}

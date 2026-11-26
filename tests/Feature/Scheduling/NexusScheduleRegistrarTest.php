<?php

namespace Tests\Feature\Scheduling;

use App\Console\NexusScheduleRegistrar;
use App\Enums\NexusRuntime;
use App\Jobs\DispatchScheduledAlertBatchesJob;
use App\Jobs\ExpireFederationResourcesJob;
use App\Jobs\ReconcileFederationLinksJob;
use App\Jobs\ReconcileMilcomLifecycleJob;
use App\Jobs\SweepFederationOutboxJob;
use App\Services\PWHealthService;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NexusScheduleRegistrarTest extends TestCase
{
    #[Test]
    public function heavy_raid_commands_run_in_the_background_with_cluster_overlap_locks(): void
    {
        $events = $this->registeredEvents();

        $this->assertScheduled($events, 'raids:process-profiles --limit=1000', '* * * * *', background: true, overlapMinutes: 15);
        $this->assertScheduled($events, 'raid:reconcile-predictions --days=30', '*/10 * * * *', background: true, overlapMinutes: 30);
    }

    #[Test]
    public function low_value_minute_entries_run_every_two_minutes(): void
    {
        $events = $this->registeredEvents();

        $this->assertScheduled($events, 'pw:health-check', '*/2 * * * *', background: true, overlapMinutes: 2);
        $this->assertScheduled($events, 'discord-queue:reap-leases', '*/2 * * * *', background: false, overlapMinutes: 2);
        $this->assertScheduled($events, 'recruit:nations', '*/2 * * * *', background: true, overlapMinutes: 10);
    }

    #[Test]
    public function recruitment_still_waits_for_the_politics_and_war_api(): void
    {
        $recruitment = $this->findEvent($this->registeredEvents(), 'recruit:nations');

        Cache::put(PWHealthService::CACHE_KEY_STATUS, true, 600);
        $this->assertTrue($recruitment->filtersPass($this->app));

        Cache::put(PWHealthService::CACHE_KEY_STATUS, false, 600);
        $this->assertFalse($recruitment->filtersPass($this->app));
    }

    #[Test]
    public function latency_sensitive_minute_entries_keep_their_cadence(): void
    {
        $events = $this->registeredEvents();

        $this->assertScheduled($events, 'accounts:process-deposits', '* * * * *', background: true, overlapMinutes: 10);
        $this->assertSame('* * * * *', $this->findEvent($events, 'security:check-rapid-transactions')->expression);
        $this->assertSame('* * * * *', $this->findEvent($events, DispatchScheduledAlertBatchesJob::class)->expression);
    }

    #[Test]
    public function feature_gated_entries_are_absent_while_their_features_are_disabled(): void
    {
        $events = $this->registeredEvents([
            'operations.features.coordination' => false,
            'federation.enabled' => false,
            'milcom.v2_enabled' => false,
        ]);

        foreach ([
            'operations:reconcile',
            SweepFederationOutboxJob::class,
            ReconcileFederationLinksJob::class,
            ExpireFederationResourcesJob::class,
            ReconcileMilcomLifecycleJob::class,
        ] as $identity) {
            $this->assertNull($this->findEventOrNull($events, $identity), "Expected {$identity} to be absent.");
        }

        $this->assertScheduled($events, 'war-counters:archive-stale', '0 * * * *', background: false, overlapMinutes: 55, oneServer: false);
    }

    #[Test]
    public function feature_gated_entries_are_registered_while_their_features_are_enabled(): void
    {
        $events = $this->registeredEvents([
            'operations.features.coordination' => true,
            'federation.enabled' => true,
            'milcom.v2_enabled' => true,
        ]);

        $this->assertScheduled($events, 'operations:reconcile', '* * * * *', background: false, overlapMinutes: 1);
        $this->assertTrue($this->findEvent($events, 'operations:reconcile')->filtersPass($this->app));
        $this->assertScheduled($events, SweepFederationOutboxJob::class, '* * * * *', background: false, overlapMinutes: 1);
        $this->assertScheduled($events, ReconcileMilcomLifecycleJob::class, '* * * * *', background: false, overlapMinutes: 1);
        $this->assertNull($this->findEventOrNull($events, 'war-counters:archive-stale'));
    }

    #[Test]
    public function toggling_a_feature_flag_takes_effect_on_the_next_schedule_build(): void
    {
        $this->assertNull($this->findEventOrNull(
            $this->registeredEvents(['operations.features.coordination' => false]),
            'operations:reconcile',
        ));

        $this->assertNotNull($this->findEventOrNull(
            $this->registeredEvents(['operations.features.coordination' => true]),
            'operations:reconcile',
        ));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return Collection<int, Event>
     */
    private function registeredEvents(array $config = []): Collection
    {
        config($config);

        $schedule = new Schedule;
        (new NexusScheduleRegistrar(
            new RuntimeCapabilities(NexusRuntime::Standalone),
            $this->app->make(PWHealthService::class),
        ))->register($schedule);

        return collect($schedule->events());
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function assertScheduled(
        Collection $events,
        string $identity,
        string $expression,
        bool $background,
        int $overlapMinutes,
        bool $oneServer = true,
    ): void {
        $event = $this->findEvent($events, $identity);

        $this->assertSame($expression, $event->expression, "{$identity} expression");
        $this->assertSame($background, $event->runInBackground, "{$identity} background mode");
        $this->assertTrue($event->withoutOverlapping, "{$identity} overlap protection");
        $this->assertSame($overlapMinutes, $event->expiresAt, "{$identity} overlap lock minutes");
        $this->assertSame($oneServer, $event->onOneServer, "{$identity} single-server lock");
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function findEvent(Collection $events, string $identity): Event
    {
        $event = $this->findEventOrNull($events, $identity);

        $this->assertNotNull($event, "Expected {$identity} to be scheduled.");

        return $event;
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function findEventOrNull(Collection $events, string $identity): ?Event
    {
        $matches = $events->filter(fn (Event $event): bool => $this->identity($event) === $identity);

        $this->assertLessThanOrEqual(1, $matches->count(), "Expected at most one schedule for {$identity}.");

        return $matches->first();
    }

    private function identity(Event $event): string
    {
        return $event instanceof CallbackEvent
            ? (string) $event->description
            : Str::after((string) $event->command, "'artisan' ");
    }
}

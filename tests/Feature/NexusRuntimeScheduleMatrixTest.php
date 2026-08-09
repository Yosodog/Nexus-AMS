<?php

namespace Tests\Feature;

use App\Console\NexusScheduleRegistrar;
use App\Enums\NexusRuntime;
use App\Services\PWHealthService;
use App\Services\RuntimeCapabilities;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NexusRuntimeScheduleMatrixTest extends TestCase
{
    private const STANDALONE_EVENTS = [
        'health:record-process-heartbeats@* * * * *',
        'pw:health-check@* * * * *',
        'sync:nations:rolling --scope=highscore@15 0 * * *',
        'sync:nations:rolling --scope=all@30 0 * * 1',
        'sync:alliances@15 0,12 * * *',
        'sync:wars@10 * * * *',
        'accounts:process-deposits@* * * * *',
        'loans:process-payments@15 0 * * *',
        'payroll:run-daily@30 0 * * *',
        'lottery:draw@*/5 * * * *',
        'growth-circles:distribute@0 3 * * *',
        'telescope:prune --hours=72@45 23 * * *',
        'sanctum:prune-expired --hours=24@30 23 * * *',
        'security:check-rapid-transactions@* * * * *',
        'users:disable-inactive@5 1 * * *',
        'members:reconcile-inactivity-exceptions@*/5 * * * *',
        'audit:prune@15 1 * * *',
        'account-statements:prune@25 1 * * *',
        'scheduler-lifecycle:prune@35 1 * * *',
        'war-counters:archive-stale@0 * * * *',
        'App\\Jobs\\ReconcileMilcomLifecycleJob@* * * * *',
        'App\\Jobs\\EvaluateAlertSubscriptionsJob@25 * * * *',
        'App\\Jobs\\ReconcileBlockadeReliefRequests@35 * * * *',
        'discord-queue:reap-leases@* * * * *',
        'operations:reconcile@* * * * *',
        'App\\Jobs\\SweepFederationOutboxJob@* * * * *',
        'App\\Jobs\\ReconcileFederationLinksJob@*/15 * * * *',
        'App\\Jobs\\ExpireFederationResourcesJob@*/5 * * * *',
        'App\\Jobs\\PruneFederationMessagesJob@30 2 * * *',
        'discord:sync-city-tiers@20 * * * *',
        'backup:run@30 1,7,13,19 * * *',
        'backup:monitor@10 2 * * *',
        'backup:clean@20 2 * * *',
        'taxes:collect@15 * * * *',
        'pw:sync-city-average@5 0 * * *',
        'military:sign-in@10 12 * * *',
        'inactivity:check@0 * * * *',
        'auto:withdraw@54 1-23/2 * * *',
        'audits:run@30 * * * *',
        'App\\Jobs\\SendAuditRemindersJob@0 18 * * *',
        'recruit:nations@* * * * *',
        'sync:treaties@10 * * * *',
        'trades:update@10 * * * *',
        'market-prices:refresh@12 * * * *',
        'economy-context:refresh@16 * * * *',
        'pw:sync-radiation@18 * * * *',
        'profitability:refresh@20 * * * *',
        'build-recommendations:refresh@25 */2 * * *',
        'rebuilding:refresh-estimates@0 */2 * * *',
        'App\\Jobs\\DispatchBeigeTurnAlertsJob@50 1-23/2 * * *',
        'App\\Jobs\\DispatchBeigeTurnAlertsJob@10 */2 * * *',
        'queue:prune-failed --hours=48@0 0 * * *',
    ];

    private const HOSTED_TENANT_EVENTS = [
        'health:record-process-heartbeats@* * * * *',
        'nexus:dispatch-tenant-callbacks --limit=100@* * * * *',
        'accounts:process-deposits@* * * * *',
        'loans:process-payments@15 0 * * *',
        'payroll:run-daily@30 0 * * *',
        'lottery:draw@*/5 * * * *',
        'growth-circles:distribute@0 3 * * *',
        'telescope:prune --hours=72@45 23 * * *',
        'sanctum:prune-expired --hours=24@30 23 * * *',
        'security:check-rapid-transactions@* * * * *',
        'users:disable-inactive@5 1 * * *',
        'members:reconcile-inactivity-exceptions@*/5 * * * *',
        'audit:prune@15 1 * * *',
        'account-statements:prune@25 1 * * *',
        'scheduler-lifecycle:prune@35 1 * * *',
        'war-counters:archive-stale@0 * * * *',
        'App\\Jobs\\ReconcileMilcomLifecycleJob@* * * * *',
        'App\\Jobs\\EvaluateAlertSubscriptionsJob@25 * * * *',
        'App\\Jobs\\ReconcileBlockadeReliefRequests@35 * * * *',
        'discord-queue:reap-leases@* * * * *',
        'operations:reconcile@* * * * *',
        'App\\Jobs\\SweepFederationOutboxJob@* * * * *',
        'App\\Jobs\\ReconcileFederationLinksJob@*/15 * * * *',
        'App\\Jobs\\ExpireFederationResourcesJob@*/5 * * * *',
        'App\\Jobs\\PruneFederationMessagesJob@30 2 * * *',
        'discord:sync-city-tiers@20 * * * *',
        'taxes:collect@15 * * * *',
        'pw:sync-city-average@5 0 * * *',
        'military:sign-in@10 12 * * *',
        'inactivity:check@0 * * * *',
        'auto:withdraw@54 1-23/2 * * *',
        'audits:run@30 * * * *',
        'App\\Jobs\\SendAuditRemindersJob@0 18 * * *',
        'recruit:nations@* * * * *',
        'profitability:refresh@20 * * * *',
        'build-recommendations:refresh@25 */2 * * *',
        'rebuilding:refresh-estimates@0 */2 * * *',
        'App\\Jobs\\DispatchBeigeTurnAlertsJob@50 1-23/2 * * *',
        'App\\Jobs\\DispatchBeigeTurnAlertsJob@10 */2 * * *',
        'queue:prune-failed --hours=48@0 0 * * *',
    ];

    private const WORLD_WRITER_EVENTS = [
        'pw:health-check@* * * * *',
        'sync:treaties@10 * * * *',
        'trades:update@10 * * * *',
        'market-prices:refresh@12 * * * *',
        'pw:sync-radiation@18 * * * *',
    ];

    #[Test]
    public function default_application_schedule_preserves_the_complete_standalone_matrix(): void
    {
        $this->assertSame(
            self::STANDALONE_EVENTS,
            $this->normalizeEvents($this->app->make(Schedule::class)),
        );
    }

    #[Test]
    #[DataProvider('runtimeScheduleProvider')]
    public function every_runtime_registers_only_its_classified_schedule_matrix(
        NexusRuntime $runtime,
        array $expectedEvents,
    ): void {
        $schedule = new Schedule;
        $registrar = new NexusScheduleRegistrar(
            new RuntimeCapabilities($runtime),
            $this->app->make(PWHealthService::class),
        );

        $registrar->register($schedule);

        $actualEvents = $this->normalizeEvents($schedule);

        $this->assertSame($expectedEvents, $actualEvents);
        $this->assertCount(count(array_unique($actualEvents)), $actualEvents);
    }

    /**
     * @return iterable<string, array{NexusRuntime, list<string>}>
     */
    public static function runtimeScheduleProvider(): iterable
    {
        yield 'standalone' => [NexusRuntime::Standalone, self::STANDALONE_EVENTS];
        yield 'hosted tenant' => [NexusRuntime::HostedTenant, self::HOSTED_TENANT_EVENTS];
        yield 'temporary world writer' => [NexusRuntime::WorldWriter, self::WORLD_WRITER_EVENTS];
    }

    /**
     * @return list<string>
     */
    private function normalizeEvents(Schedule $schedule): array
    {
        return array_map(function (Event $event): string {
            $identity = $event instanceof CallbackEvent
                ? $event->description
                : Str::after((string) $event->command, "'artisan' ");

            $this->assertIsString($identity);
            $this->assertNotSame('', $identity);

            return $identity.'@'.$event->expression;
        }, $schedule->events());
    }
}

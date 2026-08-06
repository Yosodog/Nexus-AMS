<?php

namespace Tests\Feature;

use App\Events\AllianceExpenseOccurred;
use App\Events\AllianceIncomeOccurred;
use App\Events\WarDeclared;
use App\Listeners\CreateCounterOnWarDeclared;
use App\Listeners\IngestMilcomIncident;
use App\Listeners\ReconcileMilcomWarState;
use App\Listeners\RecordAllianceExpense;
use App\Listeners\RecordAllianceIncome;
use Illuminate\Support\Facades\Artisan;
use Tests\FeatureTestCase;

class EventListenerRegistrationTest extends FeatureTestCase
{
    public function test_finance_listeners_are_registered_once(): void
    {
        $this->assertListenerIsRegisteredOnce(AllianceIncomeOccurred::class, RecordAllianceIncome::class);
        $this->assertListenerIsRegisteredOnce(AllianceExpenseOccurred::class, RecordAllianceExpense::class);
    }

    public function test_war_declared_listeners_use_the_explicit_order_without_discovery_duplicates(): void
    {
        $this->assertSame([
            IngestMilcomIncident::class,
            ReconcileMilcomWarState::class,
            CreateCounterOnWarDeclared::class,
        ], $this->listenersFor(WarDeclared::class));
    }

    private function assertListenerIsRegisteredOnce(string $event, string $listener): void
    {
        $listeners = $this->listenersFor($event);

        $this->assertCount(1, $listeners);
        $this->assertStringContainsString($listener, $listeners[0]);
    }

    /**
     * @return list<string>
     */
    private function listenersFor(string $event): array
    {
        Artisan::call('event:list', [
            '--event' => $event,
            '--json' => true,
        ]);

        $events = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $events);

        return $events[0]['listeners'];
    }
}

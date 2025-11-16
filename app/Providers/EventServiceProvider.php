<?php

namespace App\Providers;

use App\Events\AllianceExpenseOccurred;
use App\Events\AllianceIncomeOccurred;
use App\Events\WarDeclared;
use App\Listeners\CreateCounterOnWarDeclared;
use App\Listeners\RecordAllianceExpense;
use App\Listeners\RecordAllianceIncome;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Registers event → listener mappings for war room features.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        WarDeclared::class => [
            CreateCounterOnWarDeclared::class,
        ],
        AllianceIncomeOccurred::class => [
            RecordAllianceIncome::class,
        ],
        AllianceExpenseOccurred::class => [
            RecordAllianceExpense::class,
        ],
    ];

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}

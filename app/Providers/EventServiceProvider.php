<?php

namespace App\Providers;

use App\Events\AllianceExpenseOccurred;
use App\Events\AllianceIncomeOccurred;
use App\Events\NationAllianceChanged;
use App\Events\WarAttackRecorded;
use App\Events\WarDeclared;
use App\Events\WarStateChanged;
use App\Listeners\AuditLogin;
use App\Listeners\AuditLoginFailed;
use App\Listeners\AuditLogout;
use App\Listeners\IngestMilcomIncident;
use App\Listeners\ReconcileMilcomWarState;
use App\Listeners\RecordAllianceExpense;
use App\Listeners\RecordAllianceIncome;
use App\Listeners\SendAllianceDepartureDiscordNotification;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
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
        Login::class => [
            AuditLogin::class,
        ],
        Failed::class => [
            AuditLoginFailed::class,
        ],
        Logout::class => [
            AuditLogout::class,
        ],
        WarDeclared::class => [
            IngestMilcomIncident::class,
            ReconcileMilcomWarState::class,
        ],
        WarStateChanged::class => [
            ReconcileMilcomWarState::class,
        ],
        WarAttackRecorded::class => [
            ReconcileMilcomWarState::class,
        ],
        NationAllianceChanged::class => [
            SendAllianceDepartureDiscordNotification::class,
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

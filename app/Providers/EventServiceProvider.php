<?php

namespace App\Providers;

use App\Events\WarDeclared;
use App\Listeners\CreateCounterOnWarDeclared;
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
    ];
}

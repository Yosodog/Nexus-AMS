<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    FortifyServiceProvider::class,
    TelescopeServiceProvider::class,
];

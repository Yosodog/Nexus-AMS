<?php

namespace App\Providers;

use App\Broadcasting\PWMessageChannel;
use App\Models\CityGrantRequest;
use App\Models\Loans;
use App\Models\Nations;
use App\Models\User;
use App\Services\PWMessageService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notification::extend('pnw', function ($app) {
            return new PWMessageChannel($app->make(PWMessageService::class));
        });

        Route::model('CityGrantRequest', CityGrantRequest::class);
        Route::model('Loans', Loans::class);
        Route::model('Nations', Nations::class);

        Gate::define('viewPulse', function (User $user) {
            return $user->is_admin;
        });
    }
}

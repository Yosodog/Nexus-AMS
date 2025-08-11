<?php

namespace App\Providers;

use App\Broadcasting\PWMessageChannel;
use App\Models\CityGrantRequest;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\User;
use App\Models\WarAidRequest;
use App\Services\PWHealthService;
use App\Services\PWMessageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
        Route::model('Loan', Loan::class);
        Route::model('Nation', Nation::class);
        Route::model('WarAidRequest', WarAidRequest::class);

        Gate::define('viewPulse', function (User $user) {
            return Gate::allows('view-diagnostic-info');
        });

        foreach (config('permissions', []) as $permission) {
            Gate::define($permission, fn(User $user) => $user->hasPermission($permission));
        }

        View::composer('*', function ($view) {
            $view->with('pwApiDown', Cache::get(PWHealthService::CACHE_KEY_STATUS) === false);
            $view->with('pwApiLastChecked', Cache::get(PWHealthService::CACHE_KEY_CHECKED_AT));
        });
    }
}

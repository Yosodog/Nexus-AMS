<?php

namespace App\Providers;

use App\AutoSync\AutoSyncManager;
use App\Broadcasting\PWMessageChannel;
use App\Http\Controllers\Auth\PasswordResetLinkController as AppPasswordResetLinkController;
use App\Models\CityGrantRequest;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\Offshore;
use App\Models\OffshoreGuardrail;
use App\Models\User;
use App\Models\WarAidRequest;
use App\Observers\OffshoreGuardrailObserver;
use App\Observers\OffshoreObserver;
use App\Services\PWHealthService;
use App\Services\PWMessageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AutoSyncManager::class, fn($app) => new AutoSyncManager($app->make(PWHealthService::class)));
        $this->app->bind(FortifyPasswordResetLinkController::class, AppPasswordResetLinkController::class);

        $this->app->scoped('pw.health.view-data', function () {
            $status = Cache::get(PWHealthService::CACHE_KEY_STATUS);
            $checkedAt = Cache::get(PWHealthService::CACHE_KEY_CHECKED_AT);

            return [
                'down' => $status === false,
                'checkedAt' => $checkedAt,
            ];
        });
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

        Offshore::observe(OffshoreObserver::class);
        OffshoreGuardrail::observe(OffshoreGuardrailObserver::class);

        Gate::define('viewPulse', function (User $user) {
            return Gate::allows('view-diagnostic-info');
        });

        foreach (config('permissions', []) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        View::composer('*', function ($view) {
            $pwHealthData = app('pw.health.view-data');

            $view->with('pwApiDown', $pwHealthData['down']);
            $view->with('pwApiLastChecked', $pwHealthData['checkedAt']);
        });
    }
}

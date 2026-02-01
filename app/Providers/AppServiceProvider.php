<?php

namespace App\Providers;

use App\Actions\Fortify\LoginResponse;
use App\Broadcasting\PWMessageChannel;
use App\Http\Controllers\Auth\PasswordResetLinkController as AppPasswordResetLinkController;
use App\Logs\CronLog;
use App\Logs\SubLog;
use App\Models\CityGrantRequest;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\Offshore;
use App\Models\OffshoreGuardrail;
use App\Models\User;
use App\Models\WarAidRequest;
use App\Observers\OffshoreGuardrailObserver;
use App\Observers\OffshoreObserver;
use App\Services\AuditLogger;
use App\Services\PendingRequestsService;
use App\Services\PWHealthService;
use App\Services\PWMessageService;
use App\Services\SettingService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FortifyPasswordResetLinkController::class, AppPasswordResetLinkController::class);
        $this->app->singleton(AuditLogger::class);

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
        RateLimiter::for('account-transfers', function (Request $request) {
            $key = $request->user()?->nation_id ?? $request->ip();

            return Limit::perMinute(2)->by($key);
        });

        RateLimiter::for('grant-requests', function (Request $request) {
            $nationId = $request->user()?->nation_id;
            $nationLimit = (int) config('grants.rate_limits.nation_per_minute', 3);
            $ipLimit = (int) config('grants.rate_limits.ip_per_minute', 10);

            return [
                Limit::perMinute($nationLimit)->by('nation:'.($nationId ?? $request->ip())),
                Limit::perMinute($ipLimit)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('war-simulations', function (Request $request) {
            $key = $request->user()?->nation_id ?? $request->ip();

            return Limit::perMinute(20)->by($key);
        });

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

        View::composer(['layouts.main', 'layouts.admin', 'components.header', 'admin.components.sidebar'], function ($view) {
            $user = auth()->user();
            $pendingRequests = [
                'counts' => [],
                'total' => 0,
            ];

            if ($user) {
                $pendingRequests = app(PendingRequestsService::class)->getCountsForUser($user);
            }

            $view->with('pendingRequests', $pendingRequests);
        });

        View::composer(['layouts.main', 'layouts.admin', 'admin.settings'], function ($view) {
            $faviconPath = SettingService::getFaviconPath();
            $faviconUrl = asset('favicon.ico');

            if ($faviconPath && Storage::disk('public')->exists($faviconPath)) {
                $faviconUrl = Storage::disk('public')->url($faviconPath);
            }

            $view->with('faviconUrl', $faviconUrl);
            $view->with('faviconPath', $faviconPath);
        });

        LogViewer::auth(function ($request) {
            return Gate::allows('view-diagnostic-info');
        });

        LogViewer::extend('cron', CronLog::class);
        LogViewer::extend('sublog', SubLog::class);

        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }
}

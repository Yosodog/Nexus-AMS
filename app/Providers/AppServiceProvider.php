<?php

namespace App\Providers;

use App\Actions\Fortify\LoginResponse;
use App\Actions\Fortify\TwoFactorLoginResponse;
use App\Broadcasting\PWMessageChannel;
use App\Contracts\BootstrapTokenIntrospector;
use App\Contracts\TenantCallbackTransport;
use App\Domain\Federation\Contracts\DnsResolver;
use App\Domain\Federation\Contracts\FederationTransport;
use App\Domain\Federation\Services\FederationOperationGuard;
use App\Domain\Federation\Transport\DirectHttpTransport;
use App\Domain\Federation\Transport\NativeDnsResolver;
use App\Http\Controllers\Auth\PasswordResetLinkController as AppPasswordResetLinkController;
use App\Logs\CronLog;
use App\Logs\SubLog;
use App\Models\Application;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\BlockadeReliefRequest;
use App\Models\CityGrantRequest;
use App\Models\FederationCoalitionInvitation;
use App\Models\FederationCoalitionProposal;
use App\Models\FederationLinkInvitation;
use App\Models\FederationReceivedResource;
use App\Models\FederationReceivedVersion;
use App\Models\GrantApplication;
use App\Models\Loan;
use App\Models\MemberTransfer;
use App\Models\MilcomOperation;
use App\Models\Nation;
use App\Models\Offshore;
use App\Models\OffshoreGuardrail;
use App\Models\RebuildingRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WarAidRequest;
use App\Observers\OffshoreGuardrailObserver;
use App\Observers\OffshoreObserver;
use App\Observers\StaffWorkQueueCacheObserver;
use App\Services\AuditLogger;
use App\Services\PendingRequestsService;
use App\Services\PWHealthService;
use App\Services\PWMessageService;
use App\Services\SettingService;
use App\Services\StaffWorkQueue\OperationsReadStore;
use App\Services\StaffWorkQueue\Sources\ApplicationWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\AuditRemediationWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\BlockadeReliefWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\CityGrantWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\FederationWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\GrantWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\LoanWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\MemberTransferWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\RebuildingWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\WarAidWorkQueueSource;
use App\Services\StaffWorkQueue\Sources\WithdrawalWorkQueueSource;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use App\Services\TenantCallbacks\HttpTenantCallbackTransport;
use App\Services\TenantControl\HttpBootstrapTokenIntrospector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
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
        $this->app->bind(BootstrapTokenIntrospector::class, HttpBootstrapTokenIntrospector::class);
        $this->app->bind(TenantCallbackTransport::class, HttpTenantCallbackTransport::class);
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(FederationTransport::class, DirectHttpTransport::class);
        $this->app->singleton(FederationOperationGuard::class);
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(StaffWorkQueueRegistry::class, fn ($app): StaffWorkQueueRegistry => new StaffWorkQueueRegistry([
            $app->make(ApplicationWorkQueueSource::class),
            $app->make(CityGrantWorkQueueSource::class),
            $app->make(GrantWorkQueueSource::class),
            $app->make(LoanWorkQueueSource::class),
            $app->make(WithdrawalWorkQueueSource::class),
            $app->make(MemberTransferWorkQueueSource::class),
            $app->make(WarAidWorkQueueSource::class),
            $app->make(RebuildingWorkQueueSource::class),
            $app->make(BlockadeReliefWorkQueueSource::class),
            $app->make(AuditRemediationWorkQueueSource::class),
            $app->make(FederationWorkQueueSource::class, ['category' => FederationWorkQueueSource::LINK_APPROVALS]),
            $app->make(FederationWorkQueueSource::class, ['category' => FederationWorkQueueSource::COALITION_WORKFLOWS]),
            $app->make(FederationWorkQueueSource::class, ['category' => FederationWorkQueueSource::RECEIVED_REVIEWS]),
            $app->make(FederationWorkQueueSource::class, ['category' => FederationWorkQueueSource::BLOCKED_IMPORTS]),
            $app->make(FederationWorkQueueSource::class, ['category' => FederationWorkQueueSource::HELD_OPERATIONS]),
        ]));
        $this->app->bind(OperationsReadStore::class, fn ($app): OperationsReadStore => $app->make(StaffWorkQueueRegistry::class));

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
        if ($this->app->environment('testing')) {
            Vite::useHotFile(storage_path('framework/vite-testing.hot'));
        }

        $applicationUrl = (string) config('app.url');
        $applicationScheme = parse_url($applicationUrl, PHP_URL_SCHEME);

        URL::useOrigin($applicationUrl);

        if (is_string($applicationScheme) && in_array($applicationScheme, ['http', 'https'], true)) {
            URL::forceScheme($applicationScheme);
        }

        RateLimiter::for('tenant-bootstrap', function (Request $request): array {
            $tokenHash = $request->attributes->get('bootstrap_token_hash');
            $tokenHash = is_string($tokenHash)
                && preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) === 1
                    ? $tokenHash
                    : 'invalid';

            return [
                Limit::perMinute(5)->by('tenant-bootstrap:token:'.$tokenHash),
                Limit::perMinute(10)->by('tenant-bootstrap:ip:'.$request->ip()),
                Limit::perMinute(30)->by('tenant-bootstrap:global'),
            ];
        });

        RateLimiter::for('account-transfers', function (Request $request) {
            $key = $request->user()?->nation_id ?? $request->ip();

            return Limit::perMinute(2)->by($key);
        });

        RateLimiter::for('lottery-purchases', function (Request $request) {
            if ($request->user()?->nation_id !== null) {
                return Limit::perMinute(10)->by('nation:'.$request->user()->nation_id);
            }

            if ($request->user()?->id !== null) {
                return Limit::perMinute(10)->by('user:'.$request->user()->id);
            }

            return Limit::perMinute(10)->by('ip:'.$request->ip());
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

        RateLimiter::for('build-recommendation-regeneration', function (Request $request) {
            $userId = $request->user()?->id;
            $nationId = $request->user()?->nation_id;

            return [
                Limit::perMinute(2)->by('user:'.($userId ?? $request->ip())),
                Limit::perHour(6)->by('nation:'.($nationId ?? $request->ip())),
                Limit::perHour(20)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('verification-resends', function (Request $request) {
            return [
                Limit::perMinute(1)->by('user:'.($request->user()?->id ?? $request->ip())),
                Limit::perMinute(5)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('custom-page-previews', function (Request $request) {
            return [
                Limit::perMinute(10)->by('user:'.($request->user()?->id ?? $request->ip())),
                Limit::perMinute(30)->by('ip:'.$request->ip()),
            ];
        });

        $federationRateLimited = static function (Request $request, array $headers) {
            return response()->json([
                'error' => [
                    'code' => 'rate_limited',
                    'message' => 'The federation request could not be accepted.',
                ],
            ], 429, $headers);
        };

        RateLimiter::for('federation-handshakes', function (Request $request) use ($federationRateLimited) {
            return [
                Limit::perMinute((int) config('federation.rate_limits.handshake_ip_per_minute', 10))
                    ->by('federation:handshake:ip:'.$request->ip())
                    ->response($federationRateLimited),
                Limit::perMinute((int) config('federation.rate_limits.global_per_minute', 120))
                    ->by('federation:handshake:global')
                    ->response($federationRateLimited),
            ];
        });

        RateLimiter::for('federation-ingress', function (Request $request) use ($federationRateLimited) {
            return [
                Limit::perMinute((int) config('federation.rate_limits.ip_per_minute', 30))
                    ->by('federation:ingress:ip:'.$request->ip())
                    ->response($federationRateLimited),
                Limit::perMinute((int) config('federation.rate_limits.global_per_minute', 120))
                    ->by('federation:ingress:global')
                    ->response($federationRateLimited),
            ];
        });

        RateLimiter::for('war-simulations', function (Request $request) {
            $key = $request->user()?->nation_id ?? $request->ip();

            return Limit::perMinute(20)->by($key);
        });

        RateLimiter::for('profitability-calculations', function (Request $request) {
            return [
                Limit::perMinute(10)->by('profitability:user:'.$request->user()->getAuthIdentifier()),
                Limit::perMinute(30)->by('profitability:ip:'.$request->ip()),
                Limit::perMinute(60)->by('profitability:global'),
            ];
        });

        RateLimiter::for('raid-leaderboards', function (Request $request) {
            if ($request->route('board') === 'profitability') {
                return Limit::none();
            }

            return [
                Limit::perMinute(10)->by('raid-leaderboard:user:'.$request->user()->getAuthIdentifier()),
                Limit::perMinute(30)->by('raid-leaderboard:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('raid-finder', function (Request $request) {
            return [
                Limit::perMinute(6)->by('raid-finder:user:'.$request->user()->getAuthIdentifier()),
                Limit::perMinute(20)->by('raid-finder:ip:'.$request->ip()),
            ];
        });

        Notification::extend('pnw', function ($app) {
            return new PWMessageChannel($app->make(PWMessageService::class));
        });

        Route::model('CityGrantRequest', CityGrantRequest::class);
        Route::model('Loan', Loan::class);
        Route::model('Nation', Nation::class);
        Route::model('RebuildingRequest', RebuildingRequest::class);
        Route::model('WarAidRequest', WarAidRequest::class);

        Offshore::observe(OffshoreObserver::class);
        OffshoreGuardrail::observe(OffshoreGuardrailObserver::class);

        foreach ([
            Application::class,
            AuditResult::class,
            AuditResultEvent::class,
            BlockadeReliefRequest::class,
            CityGrantRequest::class,
            FederationCoalitionInvitation::class,
            FederationCoalitionProposal::class,
            FederationLinkInvitation::class,
            FederationReceivedResource::class,
            FederationReceivedVersion::class,
            GrantApplication::class,
            Loan::class,
            MemberTransfer::class,
            MilcomOperation::class,
            RebuildingRequest::class,
            Transaction::class,
            WarAidRequest::class,
        ] as $workQueueModel) {
            $workQueueModel::observe(StaffWorkQueueCacheObserver::class);
        }

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

        View::composer(['layouts.main', 'layouts.admin', 'admin.components.sidebar'], function ($view) {
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

        View::composer(['layouts.main', 'layouts.public', 'layouts.admin', 'admin.settings.public-site'], function ($view) {
            $faviconPath = SettingService::getFaviconPath();
            $faviconUrl = asset('favicon.ico');

            if ($faviconPath && Storage::disk('public')->exists($faviconPath)) {
                $faviconUrl = Storage::disk('public')->url($faviconPath);
            }

            $view->with('faviconUrl', $faviconUrl);
            $view->with('faviconPath', $faviconPath);
        });

        LogViewer::auth(function (Request $request): bool {
            $user = $request->user();

            return $user instanceof User
                && $user->is_admin
                && ! $user->disabled
                && $user->isVerified()
                && Gate::forUser($user)->allows('view-application-logs');
        });

        Gate::define('deleteLogFile', fn (): bool => false);
        Gate::define('deleteLogFolder', fn (): bool => false);

        LogViewer::extend('cron', CronLog::class);
        LogViewer::extend('sublog', SubLog::class);

        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
    }
}

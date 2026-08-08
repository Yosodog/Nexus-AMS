<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Listeners\PasskeyAuditSubscriber;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
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
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('registration', function (Request $request) {
            return [
                Limit::perMinute(5)->by('registration-minute:'.$request->ip()),
                Limit::perHour(20)->by('registration-hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('passkeys', function (Request $request): array {
            $actor = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(10)->by('passkeys-minute:'.$actor.'|'.$request->ip()),
                Limit::perHour(100)->by('passkeys-hour:'.$actor),
            ];
        });

        config(['passkeys.redirect' => '/user/dashboard']);

        Passkeys::authorizeLoginUsing(function (Request $request, PasskeyUser $passkeyUser, Passkey $passkey): bool {
            $allowed = $passkeyUser instanceof User && ! $passkeyUser->disabled;

            if (! $allowed && $passkeyUser instanceof User) {
                app(AuditLogger::class)->denied(
                    category: 'authentication',
                    action: 'passkey_login',
                    subject: $passkeyUser,
                    context: [
                        'reason' => 'account_disabled',
                        'passkey_id' => $passkey->getKey(),
                    ],
                    message: 'Passkey login was denied because the account is disabled.',
                    actorOverride: [
                        'type' => 'user',
                        'id' => (int) $passkeyUser->getAuthIdentifier(),
                        'name' => $passkeyUser->name,
                    ],
                );
            }

            return $allowed;
        });

        Event::subscribe(PasskeyAuditSubscriber::class);

        Fortify::loginView(function () {
            return view('auth.login');
        });

        Fortify::authenticateUsing(function (Request $request): ?User {
            $username = Str::lower((string) $request->input(Fortify::username(), ''));

            $user = User::query()
                ->whereRaw('LOWER(name) = ?', [$username])
                ->first();

            if (! $user || ! Hash::check((string) $request->input('password', ''), $user->password)) {
                return null;
            }

            return $user;
        });

        Fortify::requestPasswordResetLinkView(function () {
            return view('auth.forgot-password');
        });

        Fortify::resetPasswordView(function (Request $request) {
            return view('auth.reset-password', ['request' => $request]);
        });

        Fortify::registerView(function () {
            return view('auth.register');
        });

        Fortify::twoFactorChallengeView(function () {
            return view('auth.two-factor-challenge');
        });

        Fortify::confirmPasswordView(function (Request $request) {
            if ($request->query('return_to') === 'passkeys') {
                $request->session()->put('url.intended', route('user.settings').'#passkeys');
            }

            return view('auth.confirm-password');
        });

        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);
    }
}

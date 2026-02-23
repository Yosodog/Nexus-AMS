<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\TrustedDeviceService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Laravel\Fortify\TwoFactorAuthenticatable;

class RedirectIfTwoFactorAuthenticatable extends FortifyRedirectIfTwoFactorAuthenticatable
{
    public function __construct(
        StatefulGuard $guard,
        LoginRateLimiter $limiter,
        private readonly TrustedDeviceService $trustedDeviceService
    ) {
        parent::__construct($guard, $limiter);
    }

    public function handle($request, $next): mixed
    {
        $user = $this->validateCredentials($request);

        if (! $this->shouldChallengeForTwoFactor($user)) {
            return $next($request);
        }

        if ($this->trustedDeviceService->shouldSkipTwoFactor($request, $user)) {
            return $next($request);
        }

        return $this->twoFactorChallengeResponse($request, $user);
    }

    private function shouldChallengeForTwoFactor(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (! in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true)) {
            return false;
        }

        if (Fortify::confirmsTwoFactorAuthentication()) {
            return ! is_null($user->two_factor_secret) && ! is_null($user->two_factor_confirmed_at);
        }

        return ! is_null($user->two_factor_secret);
    }
}

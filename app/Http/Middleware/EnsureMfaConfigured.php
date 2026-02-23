<?php

namespace App\Http\Middleware;

use App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaConfigured
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $requiresMfa = SettingService::isMfaRequiredForAllUsers()
            || ($user->is_admin && SettingService::isMfaRequiredForAdmins());

        if (! $requiresMfa) {
            return $next($request);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($request->routeIs(
            'user.settings',
            'user.settings.update',
            'user.settings.mfa-secrets',
            'user.settings.trusted-devices.revoke',
            'user.settings.trusted-devices.revoke-all',
            'password.confirm',
            'password.confirm.store',
            'two-factor.*',
            'logout'
        )) {
            return $next($request);
        }

        return redirect()
            ->route('user.settings')
            ->with('alert-message', 'Multi-factor authentication is required. Configure it before continuing.')
            ->with('alert-type', 'warning');
    }
}

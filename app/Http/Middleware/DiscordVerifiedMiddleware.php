<?php

namespace App\Http\Middleware;

use App\Services\DiscordAccountService;
use App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class DiscordVerifiedMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! SettingService::isDiscordVerificationRequired()) {
            return $next($request);
        }

        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user || ! $user->isVerified()) {
            return $next($request);
        }

        $discordAccount = DiscordAccountService::getActiveAccount($user);

        if ($discordAccount) {
            return $next($request);
        }

        return redirect()->route('discord.verify.show');
    }

    /**
     * Determine if the request should skip Discord verification enforcement.
     */
    protected function shouldBypass(Request $request): bool
    {
        if (! Auth::check()) {
            return true;
        }

        return false;
    }
}

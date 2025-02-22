<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && !Auth::user()->isVerified()) {
            return redirect('/')->with([
                'alert-message' => 'You must verify your account prior to performing any actions. Please check your in-game messages for a verification code.',
                "alert-type" => 'warning',
            ]);
        }

        return $next($request);
    }
}

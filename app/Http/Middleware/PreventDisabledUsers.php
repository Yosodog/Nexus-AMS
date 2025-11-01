<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class PreventDisabledUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->disabled) {
            Auth::logout();

            return redirect()->route('login')->withErrors([
                Fortify::username() => 'Your account has been disabled.',
            ]);
        }

        return $next($request);
    }
}

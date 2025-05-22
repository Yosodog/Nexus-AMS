<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PreventDisabledUsers
{
    /**
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->disabled) {
            Auth::logout();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been disabled.',
            ]);
        }

        return $next($request);
    }
}

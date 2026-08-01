<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleRegistrationRequests
{
    public function __construct(private readonly ThrottleRequests $throttleRequests) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register.store')) {
            return $this->throttleRequests->handle($request, $next, 'registration');
        }

        return $next($request);
    }
}

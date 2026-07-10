<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTrustedHost
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment(['local', 'testing'])) {
            $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            abort_unless(is_string($configuredHost) && $configuredHost !== '', 500);
            abort_unless(
                hash_equals(strtolower($configuredHost), strtolower($request->getHost())),
                400,
            );
        }

        return $next($request);
    }
}

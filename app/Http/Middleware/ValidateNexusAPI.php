<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateNexusAPI
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nexusApiToken = config('services.nexus_api_token');
        $providedToken = trim((string) $request->header('Authorization', ''));
        $expectedToken = is_string($nexusApiToken) ? 'Bearer '.$nexusApiToken : '';

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}

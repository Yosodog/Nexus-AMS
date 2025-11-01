<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateNexusAPI
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Validate Nexus API Token
        $nexusApiToken = config('services.nexus_api_token');
        $providedToken = $request->header('Authorization');

        if ($providedToken != "Bearer $nexusApiToken") {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}

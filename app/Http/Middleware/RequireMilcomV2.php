<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMilcomV2
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('milcom.v2_enabled', true)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Milcom v2 is not enabled.',
                'error' => [
                    'code' => (bool) config('milcom.v2_requested', false)
                        ? 'milcom_rules_contract_unverified'
                        : 'milcom_v2_disabled',
                    'message' => (bool) config('milcom.v2_requested', false)
                        ? 'Milcom is temporarily unavailable while the current Politics & War rules are checked.'
                        : 'Milcom is unavailable right now.',
                ],
                'meta' => ['contract_version' => 2],
                'links' => [],
            ], 503);
        }

        return response(
            (bool) config('milcom.v2_requested', false)
                ? 'Milcom is temporarily unavailable while the current Politics & War rules are checked.'
                : 'Milcom is unavailable right now.',
            503,
        );
    }
}

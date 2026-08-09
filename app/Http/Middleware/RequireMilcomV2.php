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
                        ? 'Verify the live Politics & War rules contract before enabling Milcom v2.'
                        : 'Milcom v2 is disabled for this deployment.',
                ],
                'meta' => ['contract_version' => 2],
                'links' => [],
            ], 503);
        }

        return redirect()
            ->route('admin.war-room')
            ->with('alert-warning', 'Milcom v2 is disabled. The legacy war room remains available for rollback.');
    }
}

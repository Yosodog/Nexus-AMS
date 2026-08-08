<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectLegacyMilcomMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('milcom.v1_enabled', false) || ! $this->isLegacyMilcomPath($request)) {
            return $next($request);
        }

        if ($request->isMethodSafe() && ! $request->is('api/*')) {
            $legacyType = $request->is('admin/war-counters*') ? 'counters' : 'plans';

            return redirect()->route('admin.milcom.archive', [
                'tab' => 'legacy',
                'legacy_type' => $legacyType,
            ]);
        }

        $payload = [
            'message' => 'Legacy Milcom is read-only after the v2 cutover.',
            'error' => [
                'code' => 'legacy_milcom_gone',
                'message' => 'Use Milcom v2 for all operational changes.',
            ],
            'meta' => ['contract_version' => 2],
            'links' => [
                'archive' => route('admin.milcom.archive', ['tab' => 'legacy']),
            ],
        ];

        return $request->expectsJson() || $request->is('api/*')
            ? response()->json($payload, 410)
            : response($payload['message'], 410);
    }

    private function isLegacyMilcomPath(Request $request): bool
    {
        if ($request->route()?->getActionMethod() === 'respondToWarAssignment') {
            return true;
        }

        return $request->is('admin/war-room*')
            || $request->is('admin/war-plans*')
            || $request->is('admin/war-counters*')
            || $request->is('api/v1/war-plans*')
            || $request->is('api/v1/discord/me/wars/counter')
            || $request->is('api/v1/discord/war-counters*')
            || $request->is('api/v1/discord/war-counters/attach-channel')
            || $request->is('api/v1/discord/war-counters/archive');
    }
}

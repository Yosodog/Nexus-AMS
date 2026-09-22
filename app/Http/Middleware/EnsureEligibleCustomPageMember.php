<?php

namespace App\Http\Middleware;

use App\Services\AllianceMemberEligibilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEligibleCustomPageMember
{
    public function __construct(private readonly AllianceMemberEligibilityService $eligibilityService) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->eligibilityService->nationFor($request->user());

        return $next($request);
    }
}

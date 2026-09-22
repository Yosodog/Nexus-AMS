<?php

namespace App\Http\Middleware;

use App\Services\SeoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ApplySeoRobotsHeaders
{
    public function __construct(private SeoService $seoService) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $route = $request->route();
        $routeName = $route?->getName();
        $routeSlug = $routeName === 'pages.show' ? $route?->parameter('slug') : null;

        $isSuccessfulDiscoveryResponse = $routeName === 'seo.robots'
            || ($routeName === 'seo.sitemap' && $response->isSuccessful());

        if (! $isSuccessfulDiscoveryResponse && ! $this->seoService->isRouteIndexable($routeName, $routeSlug)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}

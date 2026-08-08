<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\TenantControlConfigurationException;
use App\Services\RuntimeBuildMetadata;
use App\Services\RuntimeCapabilities;
use App\Services\TenantControl\TenantControlEndpoint;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireHostedBootstrap
{
    public function __construct(
        private RuntimeCapabilities $capabilities,
        private RuntimeBuildMetadata $build,
        private TenantControlEndpoint $endpoint,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $hasVerifiedApplicationUrl = $this->endpoint->fromConfig('app.url') !== '';
        } catch (TenantControlConfigurationException) {
            $hasVerifiedApplicationUrl = false;
        }

        if (! $this->capabilities->acceptsBootstrapRedemption()
            || ! $this->build->managed()
            || ! $hasVerifiedApplicationUrl) {
            return new JsonResponse(
                ['message' => 'Not Found.'],
                Response::HTTP_NOT_FOUND,
                ['Cache-Control' => 'no-store, private'],
            );
        }

        return $next($request);
    }
}

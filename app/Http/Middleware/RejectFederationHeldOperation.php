<?php

namespace App\Http\Middleware;

use App\Domain\Federation\Services\FederationOperationGuard;
use App\Models\MilcomAssignment;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectFederationHeldOperation
{
    public function __construct(private readonly FederationOperationGuard $guard) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $operation = $this->operationFromRoute($request);

        if ($operation instanceof MilcomOperation) {
            $this->guard->assertMutable($operation, 'web_mutation');
        }

        return $next($request);
    }

    private function operationFromRoute(Request $request): ?MilcomOperation
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof MilcomOperation) {
                return $parameter;
            }

            if ($parameter instanceof MilcomObjective) {
                return $parameter->operation;
            }

            if ($parameter instanceof MilcomAssignment) {
                return $parameter->objective?->operation;
            }
        }

        return null;
    }
}

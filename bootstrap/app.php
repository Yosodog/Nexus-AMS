<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceTrustedHost;
use App\Http\Middleware\PreventDisabledUsers;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UpdateLastActive;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustHosts(
            at: static function (): array {
                $host = parse_url((string) config('app.url'), PHP_URL_HOST);

                return is_string($host) && $host !== ''
                    ? ['^'.preg_quote($host).'$']
                    : ['(?!)'];
            },
            subdomains: false,
        );
        $middleware->append(EnforceTrustedHost::class);
        $middleware->statefulApi();
        $middleware->prependToGroup('web', [
            AssignRequestId::class,
        ]);
        $middleware->prependToGroup('api', [
            AssignRequestId::class,
        ]);
        $middleware->appendToGroup('web', [
            UpdateLastActive::class,
            PreventDisabledUsers::class,
            SecurityHeaders::class,
        ]);
        $middleware->appendToGroup('api', [
            PreventDisabledUsers::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isDiscordActorApi = static fn (Request $request): bool => $request->is('api/v1/discord/me/*')
            || $request->is('api/v1/discord/staff/*');

        $exceptions->render(function (ValidationException $exception, Request $request) use ($isDiscordActorApi) {
            if (! $isDiscordActorApi($request)) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'The request failed validation.',
                    'details' => $exception->errors(),
                ],
                'meta' => ['contract_version' => 1],
            ], 422);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($isDiscordActorApi) {
            if (! $isDiscordActorApi($request)) {
                return null;
            }

            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'You do not have permission to perform this action.'],
                'meta' => ['contract_version' => 1],
            ], 403);
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) use ($isDiscordActorApi) {
            if (! $isDiscordActorApi($request)) {
                return null;
            }

            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'You do not have permission to perform this action.'],
                'meta' => ['contract_version' => 1],
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) use ($isDiscordActorApi) {
            if (! $isDiscordActorApi($request)) {
                return null;
            }

            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'The requested Nexus record was not found.'],
                'meta' => ['contract_version' => 1],
            ], 404);
        });

        $exceptions->reportable(function (AuthorizationException $exception) {
            $subject = null;
            $arguments = $exception->getArguments();

            if (is_array($arguments)) {
                foreach ($arguments as $argument) {
                    if ($argument instanceof Model) {
                        $subject = $argument;
                        break;
                    }
                }
            }

            app(AuditLogger::class)->denied(
                category: 'security',
                action: 'authorization_denied',
                subject: $subject,
                context: [
                    'data' => [
                        'ability' => $exception->getAbility(),
                    ],
                ],
                message: 'Authorization denied.'
            );
        });
    })->create();

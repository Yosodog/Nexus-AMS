<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\PreventDisabledUsers;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UpdateLastActive;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
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

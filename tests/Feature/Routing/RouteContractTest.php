<?php

namespace Tests\Feature\Routing;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteContractTest extends TestCase
{
    use RefreshDatabase;

    private const ENHANCED_ROUTE_CONTRACT_SHA256 = '7e193f0c766a93d3cf16e768122aaa6c739063b229c5c37dd302cd8ec15500b6';

    public function createApplication(): Application
    {
        $telescopeEnabled = $this->environmentValue('TELESCOPE_ENABLED');
        $routesCache = $this->environmentValue('APP_ROUTES_CACHE');
        $this->setEnvironmentValue('TELESCOPE_ENABLED', 'true');
        $this->setEnvironmentValue(
            'APP_ROUTES_CACHE',
            'storage/framework/cache/route-contract-'.getmypid().'.php',
        );

        try {
            return parent::createApplication();
        } finally {
            $this->restoreEnvironmentValue('TELESCOPE_ENABLED', $telescopeEnabled);
            $this->restoreEnvironmentValue('APP_ROUTES_CACHE', $routesCache);
        }
    }

    public function test_complete_route_list_matches_the_supported_ordered_contract(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/Routing/route-contract.json'));

        $this->assertNotFalse($fixture);

        /** @var list<array<string, mixed>> $expected */
        $expected = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);

        $router = app(Router::class);
        $middlewareGroups = $router->getMiddlewareGroups();
        $router->flushMiddlewareGroups();

        try {
            $actual = collect(Route::getRoutes()->getRoutes())
                ->map(fn (LaravelRoute $route): array => [
                    'domain' => $route->domain(),
                    'method' => implode('|', $route->methods()),
                    'uri' => $this->resolveUri($route),
                    'name' => $route->getName(),
                    'action' => ltrim($route->getActionName(), '\\'),
                    'middleware' => collect($router->gatherRouteMiddleware($route))
                        ->map(static fn ($middleware): string => $middleware instanceof Closure ? 'Closure' : $middleware)
                        ->all(),
                ])
                ->all();
        } finally {
            foreach ($middlewareGroups as $name => $middleware) {
                $router->middlewareGroup($name, $middleware);
            }
        }

        $this->assertCount(558, $expected);
        $this->assertCount(558, $actual);

        foreach ($expected as $index => $expectedRoute) {
            $this->assertSame(
                $expectedRoute,
                $actual[$index],
                "Route definition {$index} no longer matches the supported contract.",
            );
        }
    }

    public function test_route_fallback_behavior_matches_the_supported_contract(): void
    {
        $fallbacks = collect(Route::getRoutes()->getRoutes())
            ->values()
            ->filter(static fn ($route): bool => $route->isFallback)
            ->map(static fn ($route, int $index): array => [
                'index' => $index,
                'domain' => $route->domain(),
                'methods' => $route->methods(),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => $route->getActionName(),
                'middleware' => $route->gatherMiddleware(),
            ])
            ->all();

        $this->assertSame([], $fallbacks);
    }

    public function test_complete_route_metadata_matches_the_supported_contract(): void
    {
        $contract = $this->enhancedRouteContract($this->app);

        $this->assertCount(558, $contract);
        $this->assertSame(
            self::ENHANCED_ROUTE_CONTRACT_SHA256,
            hash('sha256', $this->canonicalJson($contract)),
        );

        $savedViewRoute = collect($contract)->firstWhere('name', 'admin.work-queue.saved-views.destroy');
        $this->assertIsArray($savedViewRoute);
        $this->assertSame([
            'savedView' => '[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}',
        ], $savedViewRoute['wheres']);

        foreach ([
            'admin.members.inactivity-exceptions.update',
            'admin.members.inactivity-exceptions.destroy',
        ] as $routeName) {
            $nestedExceptionRoute = collect($contract)->firstWhere('name', $routeName);

            $this->assertIsArray($nestedExceptionRoute);
            $this->assertTrue($nestedExceptionRoute['enforces_scoped_bindings']);
            $this->assertFalse($nestedExceptionRoute['prevents_scoped_bindings']);
        }
    }

    public function test_a_freshly_compiled_route_cache_reloads_the_same_complete_contract(): void
    {
        $uncachedContract = $this->enhancedRouteContract($this->app);
        $telescopeEnabled = $this->environmentValue('TELESCOPE_ENABLED');
        $previousContainer = Container::getInstance();
        $previousFacadeApplication = Facade::getFacadeApplication();
        $previousDatabaseResolver = Model::getConnectionResolver();
        $previousEventDispatcher = Model::getEventDispatcher();
        $routes = $this->app->make(Router::class)->getRoutes();

        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
        $compiledRoutes = $routes->compile();

        $this->setEnvironmentValue('TELESCOPE_ENABLED', 'true');

        try {
            /** @var Application $cachedApplication */
            $cachedApplication = require base_path('bootstrap/app.php');
            $cachedApplication->booting(function () use ($cachedApplication, $compiledRoutes): void {
                $cachedApplication->instance('routes.cached', true);
                RouteServiceProvider::loadCachedRoutesUsing(
                    static fn () => app('router')->setCompiledRoutes($compiledRoutes),
                );
            });
            $cachedApplication->make(ConsoleKernel::class)->bootstrap();

            if ($previousDatabaseResolver !== null) {
                $cachedApplication->instance('db', $previousDatabaseResolver);
                Model::setConnectionResolver($previousDatabaseResolver);
            }

            $this->assertTrue($cachedApplication->routesAreCached());
            $this->assertSame(
                hash('sha256', $this->canonicalJson($uncachedContract)),
                hash('sha256', $this->canonicalJson($this->enhancedRouteContract($cachedApplication))),
            );
        } finally {
            RouteServiceProvider::loadCachedRoutesUsing(null);
            Container::setInstance($previousContainer);

            if ($previousDatabaseResolver === null) {
                Model::unsetConnectionResolver();
            } else {
                Model::setConnectionResolver($previousDatabaseResolver);
            }

            if ($previousEventDispatcher === null) {
                Model::unsetEventDispatcher();
            } else {
                Model::setEventDispatcher($previousEventDispatcher);
            }

            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApplication);
            $this->restoreEnvironmentValue('TELESCOPE_ENABLED', $telescopeEnabled);
        }
    }

    private function resolveUri(LaravelRoute $route): string
    {
        $uri = $route->uri();

        foreach ($route->bindingFields() as $parameter => $field) {
            $uri = str_replace("{{$parameter}}", "{{$parameter}:{$field}}", $uri);
        }

        return $uri;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function enhancedRouteContract(Application $application): array
    {
        $router = $application->make(Router::class);

        return collect($router->getRoutes()->getRoutes())
            ->map(fn (LaravelRoute $route): array => [
                'domain' => $route->domain(),
                'methods' => $route->methods(),
                'uri' => $route->uri(),
                'name' => $this->supportedRouteName($route),
                'action' => ltrim($route->getActionName(), '\\'),
                'wheres' => $route->wheres,
                'binding_fields' => $route->bindingFields(),
                'defaults' => $route->defaults,
                'is_fallback' => (bool) $route->isFallback,
                'enforces_scoped_bindings' => $route->enforcesScopedBindings(),
                'prevents_scoped_bindings' => $route->preventsScopedBindings(),
                'raw_middleware' => $this->normalizeMiddleware($route->gatherMiddleware()),
                'excluded_middleware' => $this->normalizeMiddleware($route->excludedMiddleware()),
                'expanded_middleware' => $this->normalizeMiddleware($router->gatherRouteMiddleware($route)),
            ])
            ->values()
            ->all();
    }

    private function supportedRouteName(LaravelRoute $route): ?string
    {
        $name = $route->getName();

        return $name !== null && str_starts_with($name, 'generated::') ? null : $name;
    }

    /**
     * @param  array<int, mixed>  $middleware
     * @return list<string>
     */
    private function normalizeMiddleware(array $middleware): array
    {
        return collect($middleware)
            ->map(static fn (mixed $item): string => $item instanceof Closure ? 'Closure' : (string) $item)
            ->values()
            ->all();
    }

    /** @param array<int, array<string, mixed>> $contract */
    private function canonicalJson(array $contract): string
    {
        $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (array_is_list($value)) {
                return array_map($canonicalize, $value);
            }

            ksort($value, SORT_STRING);

            foreach ($value as $key => $item) {
                $value[$key] = $canonicalize($item);
            }

            return $value;
        };

        return json_encode(
            $canonicalize($contract),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function environmentValue(string $key): string|false
    {
        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return (string) $_SERVER[$key];
        }

        return getenv($key);
    }

    private function setEnvironmentValue(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function restoreEnvironmentValue(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        $this->setEnvironmentValue($key, $value);
    }
}

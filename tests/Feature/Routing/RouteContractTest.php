<?php

namespace Tests\Feature\Routing;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteContractTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $this->setTelescopeEnabledEnvironment('true');

        try {
            return parent::createApplication();
        } finally {
            $this->setTelescopeEnabledEnvironment('false');
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

    private function resolveUri(LaravelRoute $route): string
    {
        $uri = $route->uri();

        foreach ($route->bindingFields() as $parameter => $field) {
            $uri = str_replace("{{$parameter}}", "{{$parameter}:{$field}}", $uri);
        }

        return $uri;
    }

    private function setTelescopeEnabledEnvironment(string $value): void
    {
        putenv("TELESCOPE_ENABLED={$value}");
        $_ENV['TELESCOPE_ENABLED'] = $value;
        $_SERVER['TELESCOPE_ENABLED'] = $value;
    }
}

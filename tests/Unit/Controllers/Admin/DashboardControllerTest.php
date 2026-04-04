<?php

namespace Tests\Unit\Controllers\Admin;

use App\Http\Controllers\Admin\DashboardController;
use App\Services\AllianceMembershipService;
use Tests\FeatureTestCase;

class DashboardControllerTest extends FeatureTestCase
{
    public function test_normalize_metrics_for_view_handles_incomplete_collection_payloads(): void
    {
        $controller = new DashboardController($this->createMock(AllianceMembershipService::class));

        $metrics = [
            'topInfrastructureCities' => $this->makeIncompleteCollectionPayload([
                [
                    'id' => 1,
                    'name' => 'Cached City',
                    'infrastructure' => 1500,
                    'land' => 250,
                    'nation' => [
                        'id' => 5,
                        'leader_name' => 'Cache Leader',
                        'nation_name' => 'Cache Nation',
                    ],
                ],
            ]),
            'topCashHolders' => [],
            'topScoringNations' => [],
            'activeWarDetails' => [],
            'recentWars' => [],
        ];

        $this->assertTrue($this->invokePrivate($controller, 'containsIncompleteObject', [$metrics]));

        $normalized = $this->invokePrivate($controller, 'normalizeMetricsForView', [$metrics]);

        $this->assertIsArray($normalized['topInfrastructureCities']);
        $this->assertCount(1, $normalized['topInfrastructureCities']);
        $this->assertIsObject($normalized['topInfrastructureCities'][0]);
        $this->assertSame('Cached City', $normalized['topInfrastructureCities'][0]->name);
        $this->assertSame('Cache Leader', $normalized['topInfrastructureCities'][0]->nation->leader_name);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function makeIncompleteCollectionPayload(array $items): object
    {
        return unserialize(
            serialize(collect($items)),
            ['allowed_classes' => false]
        );
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivate(object $instance, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $arguments);
    }
}

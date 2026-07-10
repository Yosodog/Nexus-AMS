<?php

namespace Tests\Unit\Controllers\Admin;

use App\Http\Controllers\Admin\DashboardController;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Mockery;
use Tests\FeatureTestCase;

class DashboardControllerTest extends FeatureTestCase
{
    public function test_normalize_metrics_for_view_handles_incomplete_collection_payloads(): void
    {
        $controller = new DashboardController($this->createMock(AllianceMembershipService::class));

        $metrics = [
            'activeWarDetails' => $this->makeIncompleteCollectionPayload([
                [
                    'id' => 10,
                    'att_id' => 5,
                    'def_id' => 8,
                    'war_type' => 'raid',
                    'turns_left' => 42,
                    'attacker' => [
                        'id' => 5,
                        'leader_name' => 'Cached Attacker',
                    ],
                ],
            ]),
        ];

        $this->assertTrue($this->invokePrivate($controller, 'containsIncompleteObject', [$metrics]));

        $normalized = $this->invokePrivate($controller, 'normalizeMetricsForView', [$metrics]);

        $this->assertIsArray($normalized['activeWarDetails']);
        $this->assertCount(1, $normalized['activeWarDetails']);
        $this->assertIsObject($normalized['activeWarDetails'][0]);
        $this->assertSame('raid', $normalized['activeWarDetails'][0]->war_type);
        $this->assertSame('Cached Attacker', $normalized['activeWarDetails'][0]->attacker->leader_name);
    }

    public function test_dashboard_metrics_are_filtered_by_staff_permissions(): void
    {
        $controller = new DashboardController($this->createMock(AllianceMembershipService::class));
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')->andReturnUsing(
            fn (string $permission): bool => $permission === 'view-loans',
        );

        $filtered = $this->invokePrivate($controller, 'filterMetricsForView', [[
            'cashTotal' => 9_000_000,
            'totalMembers' => 42,
            'activeWars' => 7,
            'loanStats' => [
                'pending' => 3,
                'active' => 5,
                'paid' => 8,
                'outstanding_balance' => 1_250_000,
                'avg_interest' => 2.5,
                'avg_term' => 8,
            ],
        ], $user]);

        $this->assertSame(0.0, $filtered['cashTotal']);
        $this->assertSame(0, $filtered['totalMembers']);
        $this->assertSame(0, $filtered['activeWars']);
        $this->assertSame(3, $filtered['loanStats']['pending']);
        $this->assertSame(1_250_000, $filtered['loanStats']['outstanding_balance']);
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

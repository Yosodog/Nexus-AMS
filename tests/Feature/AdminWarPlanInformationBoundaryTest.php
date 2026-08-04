<?php

namespace Tests\Feature;

use App\Livewire\Admin\AppSidebar;
use App\Models\Nation;
use App\Models\User;
use App\Models\WarPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AdminWarPlanInformationBoundaryTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_requested', false);
        config()->set('milcom.v2_enabled', false);
    }

    public function test_war_viewer_cannot_read_operational_plans_or_plan_data(): void
    {
        $admin = $this->createAdmin(['view-wars']);
        $plan = WarPlan::query()->create(['name' => 'Restricted Operation']);
        $target = $plan->targets()->create([
            'nation_id' => Nation::factory()->create()->id,
            'target_priority_score' => 88.5,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.wars'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.war-room'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.war-plans.show', $plan))
            ->assertForbidden();

        foreach ([
            'admin.war-plans.export',
            'admin.war-plans.targets.export-csv',
            'admin.war-plans.assignments.export-csv',
        ] as $routeName) {
            $this->actingAs($admin)
                ->get(route($routeName, $plan))
                ->assertForbidden();
        }

        $this->actingAsSanctum($admin);

        foreach ([
            'api.admin.war-plans.targets',
            'api.admin.war-plans.assignments',
            'api.admin.war-plans.friendlies',
        ] as $routeName) {
            $this->getJson(route($routeName, $plan))->assertForbidden();
        }

        $this->getJson(route('api.admin.war-plans.target-candidates', [$plan, $target]))
            ->assertForbidden();
    }

    public function test_war_room_manager_can_read_operational_plans_and_plan_data(): void
    {
        $admin = $this->createAdmin(['manage-war-room']);
        $plan = WarPlan::query()->create(['name' => 'Authorized Operation']);

        $this->actingAs($admin)
            ->get(route('admin.war-room'))
            ->assertOk()
            ->assertSee('Authorized Operation');

        $this->actingAs($admin)
            ->get(route('admin.war-plans.show', $plan))
            ->assertOk()
            ->assertSee('Authorized Operation');

        $this->actingAs($admin)
            ->get(route('admin.war-plans.export', $plan))
            ->assertOk()
            ->assertJsonPath('metadata.name', 'Authorized Operation');

        foreach ([
            'admin.war-plans.targets.export-csv',
            'admin.war-plans.assignments.export-csv',
        ] as $routeName) {
            $this->actingAs($admin)
                ->get(route($routeName, $plan))
                ->assertOk()
                ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }

        $this->actingAsSanctum($admin);

        foreach ([
            'api.admin.war-plans.targets',
            'api.admin.war-plans.assignments',
            'api.admin.war-plans.friendlies',
        ] as $routeName) {
            $this->getJson(route($routeName, $plan))->assertOk();
        }
    }

    public function test_war_room_navigation_requires_management_permission(): void
    {
        $warViewer = $this->createAdmin(['view-wars']);

        $this->actingAs($warViewer);

        Livewire::test(AppSidebar::class)
            ->assertSee('Wars')
            ->assertDontSee('War room');

        $warRoomManager = $this->createAdmin(['manage-war-room']);

        $this->actingAs($warRoomManager);

        Livewire::test(AppSidebar::class)
            ->assertSee('War room');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin([
            'nation_id' => fake()->unique()->numberBetween(700_000, 749_999),
        ]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AppNavbar;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class CommandPaletteTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    private const ALLIANCE_ID = 9876;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pw.alliance_id' => self::ALLIANCE_ID]);
        app(AllianceMembershipService::class)->clear();
    }

    public function test_palette_only_renders_destinations_the_staff_member_may_view(): void
    {
        $admin = $this->createAdmin(['view-members']);

        $this->actingAs($admin);

        Livewire::test(AppNavbar::class)
            ->assertSee('data-command-id="overview"', false)
            ->assertSee('data-command-id="members"', false)
            ->assertSee('data-command-id="cities"', false)
            ->assertDontSee('data-command-id="finance-ledger"', false)
            ->assertDontSee('data-command-id="audit-logs"', false)
            ->assertSee(route('admin.command-palette.search'), false)
            ->assertSee('This palette never performs mutations.');
    }

    public function test_palette_omits_the_entity_endpoint_when_member_search_is_not_authorized(): void
    {
        $admin = $this->createAdmin(['view-loans']);

        $this->actingAs($admin);

        Livewire::test(AppNavbar::class)
            ->assertSee('data-command-id="loans"', false)
            ->assertDontSee('data-entity-search-url', false)
            ->assertDontSee('data-command-id="members"', false);

        $this->getJson(route('admin.command-palette.search', ['query' => 'aa']))
            ->assertForbidden();
    }

    public function test_member_search_is_scoped_to_authorized_alliance_members(): void
    {
        $admin = $this->createAdmin(['view-members']);
        $visible = Nation::factory()->create([
            'id' => 445501,
            'alliance_id' => self::ALLIANCE_ID,
            'nation_name' => 'Target Republic',
            'leader_name' => 'Visible Leader',
        ]);
        Nation::factory()->create([
            'id' => 445502,
            'alliance_id' => 1111,
            'nation_name' => 'Target Outside',
            'leader_name' => 'Outside Leader',
        ]);
        Nation::factory()->create([
            'id' => 445503,
            'alliance_id' => self::ALLIANCE_ID,
            'alliance_position' => 'APPLICANT',
            'nation_name' => 'Target Applicant',
            'leader_name' => 'Applicant Leader',
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.command-palette.search', ['query' => 'Target']));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.id', 'member:'.$visible->id)
            ->assertJsonPath('results.0.label', 'Target Republic')
            ->assertJsonPath('results.0.description', 'Visible Leader · Nation #'.$visible->id)
            ->assertJsonPath('results.0.url', route('admin.members.show', $visible))
            ->assertJsonMissingPath('results.0.discord_id')
            ->assertJsonMissingPath('results.0.alliance_id');
    }

    public function test_member_search_supports_exact_nation_ids_and_validates_short_queries(): void
    {
        $admin = $this->createAdmin(['view-members']);
        $visible = Nation::factory()->create([
            'id' => 445504,
            'alliance_id' => self::ALLIANCE_ID,
            'nation_name' => 'Numerical Search Nation',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.command-palette.search', ['query' => (string) $visible->id]))
            ->assertOk()
            ->assertJsonPath('results.0.id', 'member:'.$visible->id);

        $this->actingAs($admin)
            ->getJson(route('admin.command-palette.search', ['query' => 'x']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');
    }

    public function test_palette_search_route_is_read_only(): void
    {
        $route = Route::getRoutes()->getByName('admin.command-palette.search');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => fake()->unique()->numberBetween(800000, 899999)]);
        $this->attachDiscordAccount($admin);

        return $this->grantPermissions($admin, $permissions);
    }
}

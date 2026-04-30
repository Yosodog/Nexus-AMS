<?php

namespace Tests\Feature;

use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FeatureTestCase;

class UserDashboardTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_verified_member_can_view_the_dashboard(): void
    {
        $nation = Nation::factory()->create([
            'leader_name' => 'Dashboard Leader',
            'nation_name' => 'Dashboard Nation',
            'num_cities' => 8,
        ]);

        $user = User::factory()->verified()->create([
            'nation_id' => $nation->id,
        ]);

        $response = $this->actingAs($user)->get(route('user.dashboard'));

        $response
            ->assertOk()
            ->assertSee('Dashboard Leader')
            ->assertSee('Treasury lane')
            ->assertSee('Nation trendlines')
            ->assertSee('Recent transactions');
    }
}

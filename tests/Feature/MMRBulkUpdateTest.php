<?php

namespace Tests\Feature;

use App\Models\MMRTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class MMRBulkUpdateTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_bulk_update_validates_and_updates_tier_records(): void
    {
        $admin = $this->createAdmin();
        $tier = $this->createTier();

        $this->actingAs($admin)
            ->post(route('admin.mmr.updateAll'), [
                'tiers' => [
                    $tier->id => [
                        'money' => 5000,
                        'steel' => 25,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.mmr.index'))
            ->assertSessionHas('alert-type', 'success');

        $tier->refresh();

        $this->assertSame(5000, $tier->money);
        $this->assertSame(25, $tier->steel);
        $this->assertSame(0, $tier->spies);
    }

    public function test_unknown_tier_ids_reject_the_entire_bulk_update(): void
    {
        $admin = $this->createAdmin();
        $tier = $this->createTier();

        $this->actingAs($admin)
            ->from(route('admin.mmr.index'))
            ->post(route('admin.mmr.updateAll'), [
                'tiers' => [
                    $tier->id => ['money' => 5000],
                    999999 => ['money' => 9000],
                ],
            ])
            ->assertRedirect(route('admin.mmr.index'))
            ->assertSessionHasErrors('tiers.999999');

        $this->assertSame(0, $tier->fresh()->money);
    }

    public function test_non_array_tier_records_are_rejected(): void
    {
        $admin = $this->createAdmin();
        $tier = $this->createTier();

        $this->actingAs($admin)
            ->from(route('admin.mmr.index'))
            ->post(route('admin.mmr.updateAll'), [
                'tiers' => [$tier->id => 'invalid'],
            ])
            ->assertRedirect(route('admin.mmr.index'))
            ->assertSessionHasErrors("tiers.{$tier->id}");

        $this->assertSame(0, $tier->fresh()->money);
    }

    public function test_unused_per_tier_update_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('admin.mmr.update'));
    }

    private function createAdmin(): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => 930101]);
        $this->attachDiscordAccount($admin, ['discord_id' => '1930101']);

        return $this->grantPermissions($admin, ['manage-mmr']);
    }

    private function createTier(): MMRTier
    {
        return MMRTier::query()->create([
            'city_count' => 5,
            'money' => 0,
            'steel' => 0,
            'aluminum' => 0,
            'munitions' => 0,
            'uranium' => 0,
            'food' => 0,
            'gasoline' => 0,
            'barracks' => 0,
            'factories' => 0,
            'hangars' => 0,
            'drydocks' => 0,
            'missiles' => 0,
            'nukes' => 0,
            'spies' => 0,
        ]);
    }
}

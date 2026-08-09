<?php

namespace Tests\Feature\Security;

use App\Models\Alliance;
use App\Models\Nation;
use App\Models\NationAccount;
use App\Models\NationMilitary;
use App\Models\NationSignIn;
use App\Models\Role;
use App\Models\User;
use App\Models\WarPlan;
use App\Models\WarPlanAlliance;
use App\Models\WarPlanAssignment;
use App\Models\WarPlanTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarPlanJsonDataMinimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_war_plan_json_endpoints_only_return_allowlisted_nation_fields(): void
    {
        config()->set('milcom.v1_enabled', true);
        config()->set('milcom.v2_enabled', false);

        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $assignedFriendly = $this->createNationWithSensitiveSnapshots($friendlyAlliance);
        $candidateFriendly = $this->createNationWithSensitiveSnapshots($friendlyAlliance);
        $targetNation = $this->createNationWithSensitiveSnapshots($enemyAlliance);

        $plan = WarPlan::query()->create(['name' => 'Data minimization plan']);
        WarPlanAlliance::query()->create([
            'war_plan_id' => $plan->id,
            'alliance_id' => $friendlyAlliance->id,
            'role' => 'friendly',
        ]);
        $target = WarPlanTarget::query()->create([
            'war_plan_id' => $plan->id,
            'nation_id' => $targetNation->id,
            'target_priority_score' => 75,
            'preferred_war_type' => 'ordinary',
        ]);
        WarPlanAssignment::query()->create([
            'war_plan_id' => $plan->id,
            'war_plan_target_id' => $target->id,
            'friendly_nation_id' => $assignedFriendly->id,
            'match_score' => 80,
        ]);

        Sanctum::actingAs($this->createWarRoomManager($assignedFriendly));

        $responses = [
            $this->getJson("/api/v1/war-plans/{$plan->id}/targets"),
            $this->getJson("/api/v1/war-plans/{$plan->id}/assignments"),
            $this->getJson("/api/v1/war-plans/{$plan->id}/friendlies"),
            $this->getJson("/api/v1/war-plans/{$plan->id}/targets/{$target->id}/candidates"),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertSensitiveSnapshotFieldsAreAbsent($response->json());
        }

        $responses[0]
            ->assertJsonPath('targets.0.nation.leader_name', $targetNation->leader_name)
            ->assertJsonPath('targets.0.nation.account_profile.last_active', '2026-07-31T12:00:00+00:00');
        $responses[1]
            ->assertJsonPath('assignments.0.friendly_nation.leader_name', $assignedFriendly->leader_name)
            ->assertJsonMissingPath('assignments.0.friendly_nation.account_profile');
        $responses[2]
            ->assertJsonPath('friendlies.0.account_profile.last_active', '2026-07-31T12:00:00+00:00');
        $responses[3]
            ->assertJsonPath('candidates.0.friendly.id', $candidateFriendly->id)
            ->assertJsonMissingPath('candidates.0.friendly.latest_sign_in')
            ->assertJsonMissingPath('candidates.0.friendly.account_profile');
    }

    private function createNationWithSensitiveSnapshots(Alliance $alliance): Nation
    {
        $nation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'score' => 1000,
            'num_cities' => 10,
        ]);

        NationMilitary::query()->create(array_merge([
            'nation_id' => $nation->id,
            'soldiers' => 100000,
            'tanks' => 5000,
            'aircraft' => 1200,
            'ships' => 40,
            'spies' => 50,
            'missiles' => 2,
            'nukes' => 1,
        ], array_fill_keys([
            'soldiers_today',
            'tanks_today',
            'aircraft_today',
            'ships_today',
            'missiles_today',
            'nukes_today',
            'spies_today',
            'soldier_casualties',
            'soldier_kills',
            'tank_casualties',
            'tank_kills',
            'aircraft_casualties',
            'aircraft_kills',
            'ship_casualties',
            'ship_kills',
            'missile_casualties',
            'missile_kills',
            'nuke_casualties',
            'nuke_kills',
            'spy_casualties',
            'spy_kills',
            'spy_attacks',
        ], 0)));
        NationSignIn::query()->create(array_merge(array_fill_keys([
            'num_cities',
            'score',
            'wars_won',
            'wars_lost',
            'total_infrastructure_destroyed',
            'total_infrastructure_lost',
            'soldiers',
            'tanks',
            'aircraft',
            'ships',
            'missiles',
            'nukes',
            'spies',
            'soldier_kills',
            'soldier_casualties',
            'tank_kills',
            'tank_casualties',
            'aircraft_kills',
            'aircraft_casualties',
            'ship_kills',
            'ship_casualties',
            'missile_kills',
            'missile_casualties',
            'nuke_kills',
            'nuke_casualties',
            'spy_kills',
            'spy_casualties',
            'money',
            'coal',
            'oil',
            'uranium',
            'iron',
            'bauxite',
            'lead',
            'gasoline',
            'munitions',
            'steel',
            'aluminum',
            'food',
            'credits',
        ], 0), [
            'nation_id' => $nation->id,
            'mmr_score' => 95,
            'money' => 987654321,
            'coal' => 111,
            'oil' => 222,
            'uranium' => 333,
            'munitions' => 444,
            'steel' => 555,
            'aluminum' => 666,
            'food' => 777,
            'credits' => 8,
            'created_at' => '2026-07-31 12:00:00',
        ]));
        NationAccount::query()->create([
            'nation_id' => $nation->id,
            'credits' => 9,
            'discord_id' => '123456789012345678',
            'last_active' => '2026-07-31 12:00:00',
        ]);

        return $nation;
    }

    private function createWarRoomManager(Nation $nation): User
    {
        $role = Role::factory()->create();
        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission' => 'manage-war-room',
        ]);

        $user = User::factory()->admin()->verified()->create(['nation_id' => $nation->id]);
        $user->roles()->attach($role);

        return $user->refresh();
    }

    private function assertSensitiveSnapshotFieldsAreAbsent(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        $forbiddenKeys = [
            'latest_sign_in',
            'money',
            'coal',
            'oil',
            'uranium',
            'iron',
            'bauxite',
            'lead',
            'gasoline',
            'munitions',
            'steel',
            'aluminum',
            'food',
            'credits',
            'discord_id',
        ];

        foreach ($value as $key => $nestedValue) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbiddenKeys, "Sensitive field [{$key}] leaked in war-plan JSON.");

                if ($key === 'account_profile') {
                    $this->assertSame(['last_active'], array_keys($nestedValue));
                }
            }

            $this->assertSensitiveSnapshotFieldsAreAbsent($nestedValue);
        }
    }
}

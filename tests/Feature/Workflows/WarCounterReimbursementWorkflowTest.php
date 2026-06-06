<?php

namespace Tests\Feature\Workflows;

use App\Events\AllianceExpenseOccurred;
use App\Models\Account;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\User;
use App\Models\War;
use App\Models\WarCounter;
use App\Models\WarCounterAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class WarCounterReimbursementWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Event::fake([AllianceExpenseOccurred::class]);
    }

    public function test_admin_cannot_record_counter_reimbursement_for_own_nation(): void
    {
        [$admin, $counter, $member, $account] = $this->createCounterFixture();
        $admin->forceFill(['nation_id' => $member->id])->save();
        $admin = $this->grantPermissions($admin->fresh(), ['manage-war-room', 'manage-accounts']);

        $this->actingAs($admin)
            ->post(route('admin.war-counters.reimbursements.store', $counter), [
                'nation_id' => $member->id,
                'account_id' => $account->id,
                'gasoline' => 0,
                'munitions' => 0,
                'steel' => 0,
                'aluminum' => 0,
                'unit_loss_cost' => 25,
                'infra_loss_cost' => 0,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('war_counter_reimbursements', 0);
        $this->assertSame('0.00', number_format((float) $account->fresh()->money, 2, '.', ''));
    }

    public function test_counter_reimbursements_are_capped_and_idempotent(): void
    {
        [$admin, $counter, $member, $account] = $this->createCounterFixture();
        $payload = [
            'nation_id' => $member->id,
            'account_id' => $account->id,
            'gasoline' => 0,
            'munitions' => 0,
            'steel' => 0,
            'aluminum' => 0,
            'unit_loss_cost' => 51,
            'infra_loss_cost' => 0,
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($admin)
            ->from(route('admin.war-counters.show', $counter))
            ->post(route('admin.war-counters.reimbursements.store', $counter), $payload)
            ->assertRedirect(route('admin.war-counters.show', $counter))
            ->assertSessionHasErrors('unit_loss_cost');

        $this->assertDatabaseCount('war_counter_reimbursements', 0);
        $this->assertSame('0.00', number_format((float) $account->fresh()->money, 2, '.', ''));

        $idempotencyKey = (string) Str::uuid();
        $validPayload = array_merge($payload, [
            'unit_loss_cost' => 25,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.war-counters.show', $counter))
            ->post(route('admin.war-counters.reimbursements.store', $counter), $validPayload)
            ->assertRedirect(route('admin.war-counters.show', $counter))
            ->assertSessionHas('alert-type', 'success');

        $this->actingAs($admin)
            ->from(route('admin.war-counters.show', $counter))
            ->post(route('admin.war-counters.reimbursements.store', $counter), $validPayload)
            ->assertRedirect(route('admin.war-counters.show', $counter))
            ->assertSessionHas('alert-type', 'info');

        $this->assertDatabaseCount('war_counter_reimbursements', 1);
        $this->assertDatabaseHas('war_counter_reimbursements', [
            'war_counter_id' => $counter->id,
            'nation_id' => $member->id,
            'account_id' => $account->id,
            'unit_loss_cost' => 25,
        ]);
        $this->assertSame('25.00', number_format((float) $account->fresh()->money, 2, '.', ''));
    }

    /**
     * @return array{0: User, 1: WarCounter, 2: Nation, 3: Account}
     */
    private function createCounterFixture(?int $adminNationId = 990001): array
    {
        $friendlyAlliance = Alliance::factory()->create(['id' => 777]);
        $enemyAlliance = Alliance::factory()->create(['id' => 888]);
        $member = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'alliance_position' => 'MEMBER',
        ]);
        $aggressor = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'alliance_position' => 'MEMBER',
        ]);

        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => $adminNationId]),
            ['manage-war-room', 'manage-accounts']
        );

        $account = new Account;
        $account->nation_id = $member->id;
        $account->name = 'Counter Reimbursement';
        $account->save();

        $counter = WarCounter::query()->create([
            'aggressor_nation_id' => $aggressor->id,
            'status' => 'active',
            'team_size' => 1,
            'war_declaration_type' => 'ordinary',
            'war_reason' => 'Counter test',
        ]);

        WarCounterAssignment::query()->create([
            'war_counter_id' => $counter->id,
            'friendly_nation_id' => $member->id,
            'status' => 'assigned',
            'match_score' => 100,
            'is_locked' => true,
        ]);

        War::query()->create([
            'date' => now(),
            'reason' => 'Counter test',
            'war_type' => 'ORDINARY',
            'turns_left' => 10,
            'att_id' => $aggressor->id,
            'att_alliance_id' => $enemyAlliance->id,
            'att_alliance_position' => 'MEMBER',
            'def_id' => $member->id,
            'def_alliance_id' => $friendlyAlliance->id,
            'def_alliance_position' => 'MEMBER',
            'def_soldiers_lost' => 10,
            'att_infra_destroyed_value' => 1000,
        ]);

        return [$admin, $counter, $member, $account];
    }
}

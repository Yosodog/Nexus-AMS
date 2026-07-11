<?php

namespace Tests\Feature\Workflows;

use App\Enums\BlockadeReliefStatus;
use App\Jobs\ReconcileBlockadeReliefRequests;
use App\Models\DiscordAccount;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\NationAccount;
use App\Models\NationMilitary;
use App\Models\User;
use App\Models\War;
use App\Services\BlockadeRelief\BlockadeReliefNotificationService;
use App\Services\BlockadeRelief\BlockadeReliefService;
use App\Services\Discord\PrivateNotificationService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlockadeReliefWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777, 888]);
        config(['war.plan_defaults.activity_window_hours' => 72]);
    }

    public function test_linked_offshore_member_can_open_one_request_for_an_active_blockade(): void
    {
        [$user, $requester] = $this->member(1001, allianceId: 888);
        SettingService::setDiscordPrivateNotificationsEnabled(true);
        app(PrivateNotificationService::class)->updatePreferences($user, ['blockade_relief' => true]);
        $blockader = $this->nation(2001, allianceId: 999, score: 1000);
        $war = $this->war($requester, $blockader);

        $request = app(BlockadeReliefService::class)->create($user, $war->id, 'Need a naval break', 4);

        $this->assertSame(BlockadeReliefStatus::Pending, $request->status);
        $this->assertSame(1, $request->pending_key);
        $this->assertSame($blockader->id, $request->blockading_nation_id);
        $this->assertTrue($request->deadline_at->between(now()->addHours(3), now()->addHours(5)));
        $this->assertDatabaseHas('discord_queue', ['action' => 'PRIVATE_NOTIFICATION']);
        $this->assertSame(
            'blockade_relief_created',
            DiscordQueue::query()->firstOrFail()->payload['event_type'],
        );

        $this->expectException(ValidationException::class);
        app(BlockadeReliefService::class)->create($user, $war->id);
    }

    public function test_blockade_notifications_require_the_master_switch_and_recipient_opt_in(): void
    {
        [$user, $requester] = $this->member(1008, allianceId: 888);
        $blockader = $this->nation(2008, allianceId: 999, score: 1000);

        SettingService::setDiscordPrivateNotificationsEnabled(true);
        $request = app(BlockadeReliefService::class)->create($user, $this->war($requester, $blockader)->id);

        $this->assertDatabaseCount('discord_queue', 0);

        app(PrivateNotificationService::class)->updatePreferences($user, ['blockade_relief' => true]);
        app(BlockadeReliefNotificationService::class)
            ->enqueue($request, 'created', collect());

        $this->assertDatabaseCount('discord_queue', 1);
    }

    public function test_unlinked_member_and_applicant_cannot_use_blockade_relief(): void
    {
        $requester = $this->nation(1002, allianceId: 777);
        $unlinked = User::factory()->verified()->create(['nation_id' => $requester->id]);
        $blockader = $this->nation(2002, allianceId: 999);
        $war = $this->war($requester, $blockader);

        try {
            app(BlockadeReliefService::class)->create($unlinked, $war->id);
            $this->fail('An unlinked member should not be eligible.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('membership', $exception->errors());
        }

        [$applicant] = $this->member(1003, position: 'APPLICANT');
        $applicantWar = $this->war($applicant->nation, $this->nation(2003, allianceId: 999));

        $this->expectException(ValidationException::class);
        app(BlockadeReliefService::class)->create($applicant, $applicantWar->id);
    }

    public function test_eligible_candidate_can_claim_after_transactional_revalidation(): void
    {
        [$requesterUser, $requester] = $this->member(1004, score: 1000);
        $blockader = $this->nation(2004, allianceId: 999, score: 1000, ships: 30);
        $request = app(BlockadeReliefService::class)->create($requesterUser, $this->war($requester, $blockader)->id);
        [$helperUser, $helper] = $this->member(3004, score: 1000, ships: 75);

        $claimed = app(BlockadeReliefService::class)->claim($request, $helperUser);

        $this->assertSame(BlockadeReliefStatus::Claimed, $claimed->status);
        $this->assertSame($helper->id, $claimed->claimed_by_nation_id);
        $this->assertNotNull($claimed->claimed_at);
        $this->assertSame(1, $claimed->pending_key);
        $this->assertSame(0, (int) $helper->fresh()->offensive_wars_count);
    }

    public function test_candidate_without_a_free_slot_cannot_claim_unless_already_at_war_with_blockader(): void
    {
        [$requesterUser, $requester] = $this->member(1005);
        $blockader = $this->nation(2005, allianceId: 999, score: 1000, ships: 30);
        $request = app(BlockadeReliefService::class)->create($requesterUser, $this->war($requester, $blockader)->id);
        [$helperUser, $helper] = $this->member(3005, score: 1000, ships: 75, offensiveWars: 6);

        try {
            app(BlockadeReliefService::class)->claim($request, $helperUser);
            $this->fail('A helper without an offensive slot should not be eligible.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('claim', $exception->errors());
        }

        $this->war($helper, $blockader, 9005);
        $claimed = app(BlockadeReliefService::class)->claim($request->fresh(), $helperUser);

        $this->assertSame(BlockadeReliefStatus::Claimed, $claimed->status);
    }

    public function test_hourly_reconciliation_resolves_ended_blockades_and_expires_deadlines(): void
    {
        [$firstUser, $firstRequester] = $this->member(1006);
        $firstBlockader = $this->nation(2006, allianceId: 999);
        $firstWar = $this->war($firstRequester, $firstBlockader, 9006);
        $resolved = app(BlockadeReliefService::class)->create($firstUser, $firstWar->id);
        $firstWar->update(['naval_blockade' => null]);

        [$secondUser, $secondRequester] = $this->member(1007);
        $secondBlockader = $this->nation(2007, allianceId: 999);
        $expired = app(BlockadeReliefService::class)->create(
            $secondUser,
            $this->war($secondRequester, $secondBlockader, 9007)->id,
            deadlineHours: 1,
        );
        $expired->update(['deadline_at' => now()->subMinute()]);

        app(ReconcileBlockadeReliefRequests::class)->handle(app(BlockadeReliefService::class));

        $this->assertSame(BlockadeReliefStatus::Resolved, $resolved->fresh()->status);
        $this->assertSame('blockade_ended', $resolved->fresh()->resolution_reason);
        $this->assertNull($resolved->fresh()->pending_key);
        $this->assertSame(BlockadeReliefStatus::Expired, $expired->fresh()->status);
        $this->assertNotNull($expired->fresh()->expired_at);
    }

    /** @return array{0:User,1:Nation} */
    private function member(
        int $nationId,
        int $allianceId = 777,
        string $position = 'MEMBER',
        float $score = 1000,
        int $ships = 50,
        int $offensiveWars = 0,
    ): array {
        $nation = $this->nation($nationId, $allianceId, $position, $score, $ships, $offensiveWars);
        $user = User::factory()->verified()->create(['nation_id' => $nation->id, 'disabled' => false]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => (string) (200000000000000000 + $nationId),
            'unlinked_at' => null,
        ]);

        return [$user, $nation];
    }

    private function nation(
        int $id,
        int $allianceId,
        string $position = 'MEMBER',
        float $score = 1000,
        int $ships = 50,
        int $offensiveWars = 0,
    ): Nation {
        $nation = Nation::factory()->create([
            'id' => $id,
            'alliance_id' => $allianceId,
            'alliance_position' => $position,
            'score' => $score,
            'vacation_mode_turns' => 0,
            'offensive_wars_count' => $offensiveWars,
        ]);
        NationAccount::query()->create(['nation_id' => $nation->id, 'last_active' => now()->subHour()]);
        NationMilitary::query()->create($this->militaryAttributes($nation->id, $ships));

        return $nation;
    }

    private function war(Nation $requester, Nation $blockader, ?int $id = null): War
    {
        return War::query()->create([
            ...($id ? ['id' => $id] : []),
            'att_id' => $blockader->id,
            'def_id' => $requester->id,
            'att_alliance_id' => $blockader->alliance_id,
            'def_alliance_id' => $requester->alliance_id,
            'reason' => 'Blockade test',
            'turns_left' => 60,
            'naval_blockade' => $blockader->id,
        ]);
    }

    /** @return array<string, int> */
    private function militaryAttributes(int $nationId, int $ships): array
    {
        return [
            'nation_id' => $nationId,
            'soldiers' => 100000,
            'tanks' => 5000,
            'aircraft' => 1000,
            'ships' => $ships,
            'missiles' => 0,
            'nukes' => 0,
            'spies' => 50,
            'soldiers_today' => 0,
            'tanks_today' => 0,
            'aircraft_today' => 0,
            'ships_today' => 0,
            'missiles_today' => 0,
            'nukes_today' => 0,
            'spies_today' => 0,
            'soldier_casualties' => 0,
            'soldier_kills' => 0,
            'tank_casualties' => 0,
            'tank_kills' => 0,
            'aircraft_casualties' => 0,
            'aircraft_kills' => 0,
            'ship_casualties' => 0,
            'ship_kills' => 0,
            'missile_casualties' => 0,
            'missile_kills' => 0,
            'nuke_casualties' => 0,
            'nuke_kills' => 0,
            'spy_casualties' => 0,
            'spy_kills' => 0,
            'spy_attacks' => 0,
        ];
    }
}

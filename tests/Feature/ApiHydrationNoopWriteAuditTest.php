<?php

namespace Tests\Feature;

use App\Models\Alliance;
use App\Models\City;
use App\Models\Nation;
use App\Models\War;
use App\Models\WarAttack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesApiHydrationWrites;
use Tests\TestCase;

/**
 * Every model hydrated from the API must skip the write when the stored row already matches.
 */
class ApiHydrationNoopWriteAuditTest extends TestCase
{
    use CapturesApiHydrationWrites;
    use RefreshDatabase;

    public function test_alliance_with_unchanged_payload_issues_no_updates(): void
    {
        Alliance::updateFromAPI($this->allianceFromApiJson($this->allianceApiJson(777)), withNations: false);

        $updates = $this->captureUpdates(
            fn () => Alliance::updateFromAPI($this->allianceFromApiJson($this->allianceApiJson(777)), withNations: false)
        );

        $this->assertSame([], $updates, json_encode($updates));
    }

    public function test_alliance_with_one_changed_field_updates_only_that_column(): void
    {
        Alliance::updateFromAPI($this->allianceFromApiJson($this->allianceApiJson(777)), withNations: false);

        $updates = $this->captureUpdates(
            fn () => Alliance::updateFromAPI($this->allianceFromApiJson($this->allianceApiJson(777, ['rank' => 6])), withNations: false)
        );

        $this->assertSame(['rank', 'updated_at'], $this->updatedColumns($updates[0]['sql'] ?? ''), json_encode($updates));
    }

    public function test_city_with_unchanged_payload_issues_no_updates(): void
    {
        Nation::factory()->create(['id' => 9201, 'alliance_id' => null]);
        City::updateFromAPI($this->cityFromApiJson($this->cityApiJson(5001, 9201)));

        $updates = $this->captureUpdates(
            fn () => City::updateFromAPI($this->cityFromApiJson($this->cityApiJson(5001, 9201)))
        );

        $this->assertSame([], $updates, json_encode($updates));
    }

    public function test_city_with_one_changed_field_updates_only_that_column(): void
    {
        Nation::factory()->create(['id' => 9202, 'alliance_id' => null]);
        City::updateFromAPI($this->cityFromApiJson($this->cityApiJson(5002, 9202)));

        $updates = $this->captureUpdates(
            fn () => City::updateFromAPI($this->cityFromApiJson($this->cityApiJson(5002, 9202, ['infrastructure' => 1300.0])))
        );

        $this->assertSame(['infrastructure', 'updated_at'], $this->updatedColumns($updates[0]['sql'] ?? ''), json_encode($updates));
    }

    public function test_war_with_unchanged_payload_issues_no_updates(): void
    {
        $this->createWarParticipants(9301, 9302);
        War::updateFromAPI($this->warApiPayload(4001, 9301, 9302));

        $updates = $this->captureUpdates(
            fn () => War::updateFromAPI($this->warApiPayload(4001, 9301, 9302))
        );

        $this->assertSame([], $updates, json_encode($updates));
    }

    public function test_war_with_one_changed_field_updates_only_that_column(): void
    {
        $this->createWarParticipants(9303, 9304);
        War::updateFromAPI($this->warApiPayload(4002, 9303, 9304));

        $updates = $this->captureUpdates(
            fn () => War::updateFromAPI($this->warApiPayload(4002, 9303, 9304, ['turns_left' => 54]))
        );

        $this->assertSame(['turns_left', 'updated_at'], $this->updatedColumns($updates[0]['sql'] ?? ''), json_encode($updates));
    }

    public function test_war_attack_replay_with_unchanged_payload_issues_no_updates(): void
    {
        $this->createWarParticipants(9305, 9306);
        War::updateFromAPI($this->warApiPayload(4003, 9305, 9306));
        WarAttack::storeFromEvent($this->warAttackApiPayload(3001, 4003, 9305, 9306));

        $updates = $this->captureUpdates(
            fn () => WarAttack::storeFromEvent($this->warAttackApiPayload(3001, 4003, 9305, 9306))
        );

        $this->assertSame([], $updates, json_encode($updates));
    }

    public function test_flag_columns_read_as_booleans_but_serialize_as_integers(): void
    {
        $this->createWarParticipants(9307, 9308);
        $war = War::updateFromAPI($this->warApiPayload(4004, 9307, 9308));
        $alliance = Alliance::updateFromAPI($this->allianceFromApiJson($this->allianceApiJson(778)), withNations: false);

        $war = War::query()->findOrFail($war->id);
        $alliance = Alliance::query()->findOrFail($alliance->id);

        $this->assertFalse($war->att_peace);
        $this->assertTrue($war->att_fortify);
        $this->assertTrue($alliance->accept_members);
        $this->assertSame(0, $war->toArray()['att_peace']);
        $this->assertSame(1, $war->toArray()['att_fortify']);
        $this->assertSame(1, $alliance->toArray()['accept_members']);
    }

    private function createWarParticipants(int $attackerId, int $defenderId): void
    {
        Nation::factory()->create(['id' => $attackerId, 'alliance_id' => null]);
        Nation::factory()->create(['id' => $defenderId, 'alliance_id' => null]);
    }
}

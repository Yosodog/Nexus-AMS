<?php

namespace Tests\Feature;

use App\Models\Nation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesApiHydrationWrites;
use Tests\TestCase;

class NationApiHydrationNoopWriteTest extends TestCase
{
    use CapturesApiHydrationWrites;
    use RefreshDatabase;

    public function test_an_unchanged_payload_issues_no_updates(): void
    {
        Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9001)));

        $updates = $this->captureUpdates(
            fn () => Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9001)))
        );

        $this->assertSame([], $updates, 'Re-applying an identical payload must not write: '.json_encode($updates));
    }

    public function test_false_booleans_and_null_values_do_not_cause_updates(): void
    {
        $json = $this->nationApiJson(9002, [
            'espionage_available' => false,
            'vip' => false,
            'alliance_id' => null,
            'tax_id' => null,
            'discord' => null,
            'discord_id' => null,
            'flag' => null,
            'update_tz' => null,
        ]);

        Nation::updateFromAPI($this->nationFromApiJson($json));

        $updates = $this->captureUpdates(fn () => Nation::updateFromAPI($this->nationFromApiJson($json)));

        $this->assertSame([], $updates, json_encode($updates));
    }

    public function test_a_single_changed_field_updates_only_that_column_and_the_timestamp(): void
    {
        Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9003)));

        $updates = $this->captureUpdates(
            fn () => Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9003, ['beige_turns' => 4])))
        );

        $this->assertSame(['nations'], $this->updatedTables($updates));
        $this->assertSame(['beige_turns', 'updated_at'], $this->updatedColumns($updates[0]['sql']));
    }

    public function test_flag_columns_read_as_booleans_but_serialize_as_integers(): void
    {
        Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9005, ['espionage_available' => true, 'vip' => false])));

        $nation = Nation::query()->findOrFail(9005);

        $this->assertTrue($nation->espionage_available);
        $this->assertFalse($nation->vip);
        $this->assertSame(1, $nation->toArray()['espionage_available']);
        $this->assertSame(0, $nation->toArray()['vip']);
        $this->assertStringContainsString('"espionage_available":1,', $nation->toJson());
        $this->assertStringContainsString('"vip":0,', $nation->toJson());
    }

    public function test_toggling_a_boolean_updates_only_that_column_and_the_timestamp(): void
    {
        Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9004)));

        $updates = $this->captureUpdates(
            fn () => Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9004, ['espionage_available' => false, 'vip' => true])))
        );

        $this->assertSame(['nations'], $this->updatedTables($updates));
        $this->assertSame(['espionage_available', 'vip', 'updated_at'], $this->updatedColumns($updates[0]['sql']));

        $nation = Nation::query()->findOrFail(9004);
        $this->assertFalse($nation->espionage_available);
        $this->assertTrue($nation->vip);
    }
}

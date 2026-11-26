<?php

namespace Tests\Integration;

use App\Models\Nation;
use Tests\Concerns\CapturesApiHydrationWrites;

/**
 * MySQL returns decimals as strings and booleans as 0/1, which is what made every
 * nation look dirty on each API update. SQLite does not reproduce the decimal case.
 */
class NationApiHydrationNoopWriteTest extends MySqlIntegrationTestCase
{
    use CapturesApiHydrationWrites;

    public function test_an_unchanged_payload_issues_no_updates_on_mysql(): void
    {
        Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9101)));

        $updates = $this->captureUpdates(
            fn () => Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9101)))
        );

        $this->assertSame([], $updates, json_encode($updates));
    }

    public function test_a_single_changed_field_issues_one_update_on_mysql(): void
    {
        Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9102)));

        $updates = $this->captureUpdates(
            fn () => Nation::updateFromAPI($this->nationFromApiJson($this->nationApiJson(9102, ['score' => 2400.5])))
        );

        $this->assertCount(1, $updates, json_encode($updates));
        $this->assertSame(['nations'], $this->updatedTables($updates));
        $this->assertSame(['score', 'updated_at'], $this->updatedColumns($updates[0]['sql']));
    }
}

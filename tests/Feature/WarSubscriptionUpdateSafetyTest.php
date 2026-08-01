<?php

namespace Tests\Feature;

use App\Jobs\UpdateWarJob;
use App\Models\War;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WarSubscriptionUpdateSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $quarantineFile;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [321]);
        $this->quarantineFile = sys_get_temp_dir().'/nexus-war-update-quarantine-'.bin2hex(random_bytes(6)).'.jsonl';
        config()->set('subscriptions.ingestion.quarantine_file', $this->quarantineFile);
    }

    protected function tearDown(): void
    {
        if (is_file($this->quarantineFile)) {
            unlink($this->quarantineFile);
        }

        parent::tearDown();
    }

    public function test_partial_model_update_preserves_omitted_timestamps(): void
    {
        $war = $this->createWar(910001, [
            'date' => '2026-06-01 12:00:00',
            'end_date' => '2026-06-02 12:00:00',
        ]);

        War::updateFromAPI([
            'id' => $war->id,
            'turns_left' => 7,
        ]);

        $war->refresh();

        $this->assertSame('2026-06-01 12:00:00', $war->date);
        $this->assertSame('2026-06-02 12:00:00', $war->end_date);
        $this->assertSame(7, $war->turns_left);
        $this->assertSame('Counter', $war->reason);
    }

    public function test_explicit_null_end_date_is_still_persisted(): void
    {
        $war = $this->createWar(910002, ['end_date' => '2026-06-02 12:00:00']);

        War::updateFromAPI([
            'id' => $war->id,
            'end_date' => null,
        ]);

        $this->assertNull($war->refresh()->end_date);
    }

    public function test_job_uses_existing_alliance_ids_for_partial_updates(): void
    {
        $war = $this->createWar(910003);

        (new UpdateWarJob([[
            'id' => $war->id,
            'turns_left' => 3,
        ]]))->handle();

        $this->assertSame(3, $war->refresh()->turns_left);
    }

    public function test_job_quarantines_invalid_payloads_and_continues_with_valid_records(): void
    {
        $war = $this->createWar(910004);

        (new UpdateWarJob([
            ['turns_left' => 3],
            ['id' => $war->id, 'turns_left' => 2],
        ]))->handle();

        $this->assertSame(2, $war->refresh()->turns_left);
        $this->assertFileExists($this->quarantineFile);
        $this->assertStringContainsString('invalid_war_update', file_get_contents($this->quarantineFile));
    }

    /** @param array<string, mixed> $overrides */
    private function createWar(int $id, array $overrides = []): War
    {
        return War::query()->create([
            'id' => $id,
            'date' => '2026-06-01 12:00:00',
            'end_date' => null,
            'reason' => 'Counter',
            'war_type' => 'ORDINARY',
            'turns_left' => 12,
            'att_id' => $id + 1000,
            'att_alliance_id' => 321,
            'att_alliance_position' => 'MEMBER',
            'def_id' => $id + 2000,
            'def_alliance_id' => 999,
            'def_alliance_position' => 'MEMBER',
            ...$overrides,
        ]);
    }
}

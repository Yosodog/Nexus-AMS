<?php

namespace Tests\Feature;

use App\Jobs\UpdateWarJob;
use App\Models\War;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class WarSubscriptionUpdateSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [321]);
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

    public function test_job_rethrows_invalid_payloads_for_queue_retry_and_failure_tracking(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A positive war ID is required.');

        (new UpdateWarJob([[
            'turns_left' => 3,
        ]]))->handle();
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

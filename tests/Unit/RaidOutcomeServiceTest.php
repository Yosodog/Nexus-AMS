<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\EvaluateRaidPredictionJob;
use App\Jobs\ReconcileRaidPredictionJob;
use App\Jobs\RecordRaidOutcomeAttackJob;
use App\Models\RaidPrediction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class RaidOutcomeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prediction_declaration_fields_cannot_be_changed_after_capture(): void
    {
        $prediction = RaidPrediction::query()->create([
            'war_id' => 1001,
            'attacker_nation_id' => 101,
            'target_nation_id' => 202,
            'declared_at' => now(),
            'captured_at' => now(),
            'capture_status' => RaidPrediction::CAPTURE_INCOMPLETE,
            'capture_reason' => 'No clean snapshot was available.',
        ]);

        $this->expectException(LogicException::class);
        $prediction->war_id = 1002;
        $prediction->save();
    }

    public function test_tracking_jobs_use_the_configured_queue_and_bounded_backoff(): void
    {
        config(['raids.queue' => 'raid-tracking']);
        Queue::fake();

        $evaluation = new EvaluateRaidPredictionJob(1);
        $record = new RecordRaidOutcomeAttackJob(1, 2);
        $reconcile = new ReconcileRaidPredictionJob(2);

        $this->assertSame('raid-tracking', $evaluation->queue);
        $this->assertSame('raid-tracking', $record->queue);
        $this->assertSame('raid-tracking', $reconcile->queue);
        $this->assertSame([10, 60, 300], $evaluation->backoff());
        $this->assertSame([10, 60, 300], $record->backoff());
        $this->assertSame([30, 120, 600], $reconcile->backoff());
    }
}

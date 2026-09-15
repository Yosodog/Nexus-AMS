<?php

namespace Tests\Feature;

use App\Models\RaidPrediction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class RaidPredictionIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_persisted_declaration_inputs_cannot_be_overwritten(): void
    {
        $prediction = RaidPrediction::query()->create([
            'war_id' => 1, 'attacker_nation_id' => 10, 'target_nation_id' => 20,
            'declared_at' => now(), 'captured_at' => now(),
            'frozen_payload' => ['resources' => ['money' => 100]],
        ]);
        $this->expectException(LogicException::class);
        $prediction->update(['frozen_payload' => ['resources' => ['money' => 500]]]);
    }
}

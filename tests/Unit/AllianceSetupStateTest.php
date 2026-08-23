<?php

namespace Tests\Unit;

use App\DataTransferObjects\AllianceSetupState;
use App\Enums\AllianceSetupStatus;
use App\Enums\AllianceSetupStep;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AllianceSetupStateTest extends TestCase
{
    #[Test]
    public function current_state_round_trips_without_losing_typed_values(): void
    {
        $state = new AllianceSetupState(
            AllianceSetupState::VERSION,
            AllianceSetupStatus::InProgress,
            AllianceSetupStep::Discord,
            '2026-08-23T12:00:00+00:00',
            10,
            '2026-08-23T12:01:00+00:00',
            11,
        );

        $decoded = AllianceSetupState::fromJson($state->toJson());

        $this->assertSame($state->toArray(), $decoded->toArray());
        $this->assertFalse($decoded->corrupt);
        $this->assertTrue($decoded->isIncomplete());
    }

    #[Test]
    public function unsupported_or_malformed_state_is_incomplete_and_not_serialized_as_valid(): void
    {
        $unsupported = AllianceSetupState::fromJson('{"version":2,"status":"completed","current_step":"review"}');
        $malformed = AllianceSetupState::fromJson('{broken');

        $this->assertTrue($unsupported->corrupt);
        $this->assertTrue($unsupported->isIncomplete());
        $this->assertTrue($malformed->corrupt);
    }

    #[Test]
    public function a_missing_legacy_state_is_represented_as_completed(): void
    {
        $state = AllianceSetupState::legacyCompleted();

        $this->assertTrue($state->legacy);
        $this->assertFalse($state->stored);
        $this->assertFalse($state->isIncomplete());
    }
}

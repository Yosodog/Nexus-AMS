<?php

namespace Tests\Unit\Services;

use App\Enums\MemberInactivityAutomation;
use App\Models\MemberInactivityException;
use App\Models\Nation;
use App\Services\MemberInactivityExceptionEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberInactivityExceptionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_window_start_is_inclusive_and_end_is_exclusive(): void
    {
        $at = CarbonImmutable::parse('2026-08-06 12:00:00 UTC');
        $startsNowNation = Nation::factory()->create();
        $endsNowNation = Nation::factory()->create();

        MemberInactivityException::factory()->create([
            'nation_id' => $startsNowNation->id,
            'starts_at' => $at,
            'ends_at' => $at->addHour(),
            'affected_automations' => [MemberInactivityAutomation::DisableAccount],
        ]);
        MemberInactivityException::factory()->create([
            'nation_id' => $endsNowNation->id,
            'starts_at' => $at->subHour(),
            'ends_at' => $at,
            'affected_automations' => [MemberInactivityAutomation::DisableAccount],
        ]);

        $evaluator = app(MemberInactivityExceptionEvaluator::class);

        $this->assertTrue($evaluator->suppresses(
            $startsNowNation,
            MemberInactivityAutomation::DisableAccount,
            $at,
        ));
        $this->assertFalse($evaluator->suppresses(
            $startsNowNation,
            MemberInactivityAutomation::DisableAccount,
            $at->subSecond(),
        ));
        $this->assertFalse($evaluator->suppresses(
            $endsNowNation,
            MemberInactivityAutomation::DisableAccount,
            $at,
        ));
    }

    public function test_only_selected_automations_are_suppressed_and_revoked_records_are_ignored(): void
    {
        $at = CarbonImmutable::parse('2026-08-06 12:00:00 UTC');
        $nation = Nation::factory()->create();
        MemberInactivityException::factory()->create([
            'nation_id' => $nation->id,
            'starts_at' => $at->subHour(),
            'ends_at' => $at->addHour(),
            'affected_automations' => [MemberInactivityAutomation::SendInGameMessage],
        ]);
        MemberInactivityException::factory()->create([
            'nation_id' => $nation->id,
            'starts_at' => $at->subHour(),
            'ends_at' => $at->addHour(),
            'affected_automations' => [MemberInactivityAutomation::DisableAccount],
            'revoked_at' => $at->subMinute(),
        ]);

        $evaluator = app(MemberInactivityExceptionEvaluator::class);

        $this->assertTrue($evaluator->suppresses(
            $nation,
            MemberInactivityAutomation::SendInGameMessage,
            $at,
        ));
        $this->assertFalse($evaluator->suppresses(
            $nation,
            MemberInactivityAutomation::DisableAccount,
            $at,
        ));
        $this->assertSame([], $evaluator->nationIdsSuppressing(
            MemberInactivityAutomation::DisableAccount,
            $at,
        ));
    }

    public function test_member_visible_projection_excludes_private_notes_and_staff_identity(): void
    {
        $at = CarbonImmutable::parse('2026-08-06 12:00:00 UTC');
        $nation = Nation::factory()->create();
        MemberInactivityException::factory()->create([
            'nation_id' => $nation->id,
            'starts_at' => $at->subHour(),
            'ends_at' => $at->addHour(),
            'member_reason' => 'Public practical effect.',
            'private_notes' => 'Do not disclose this evidence.',
            'affected_automations' => [MemberInactivityAutomation::SendDiscordNotification],
        ]);

        $effects = app(MemberInactivityExceptionEvaluator::class)
            ->memberVisibleEffectsForNation($nation, $at);
        $serialized = json_encode($effects, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $effects);
        $this->assertStringContainsString('Public practical effect.', $serialized);
        $this->assertStringContainsString('Discord inactivity notifications', $serialized);
        $this->assertStringNotContainsString('Do not disclose this evidence.', $serialized);
        $this->assertStringNotContainsString('approved_by_user_id', $serialized);
    }
}

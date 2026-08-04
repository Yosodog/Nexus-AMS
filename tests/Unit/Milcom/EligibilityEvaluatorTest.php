<?php

namespace Tests\Unit\Milcom;

use App\Domain\Milcom\EligibilityEvaluator;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\MilcomGameRules;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Milcom\Concerns\BuildsReadinessProfiles;

class EligibilityEvaluatorTest extends TestCase
{
    use BuildsReadinessProfiles;

    private EligibilityEvaluator $evaluator;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new EligibilityEvaluator(new MilcomGameRules);
        $this->now = new DateTimeImmutable('2026-08-02T12:00:00+00:00');
    }

    #[Test]
    public function a_fresh_available_member_is_eligible_without_findings(): void
    {
        $result = $this->evaluator->evaluate(
            $this->profile(),
            $this->profile(['nationId' => 2]),
            [100],
            OperationType::Counter,
            at: $this->now,
        );

        $this->assertTrue($result->eligible());
        $this->assertSame([], $result->blockers);
        $this->assertSame([], $result->warnings);
    }

    #[Test]
    public function an_explicitly_included_nation_is_allowed_without_whitelisting_its_entire_alliance(): void
    {
        $result = $this->evaluator->evaluate(
            $this->profile(['allianceId' => 999]),
            $this->profile(['nationId' => 2]),
            [100],
            OperationType::Plan,
            at: $this->now,
            allowedFriendlyNationIds: [1],
        );

        $this->assertTrue($result->eligible());
        $this->assertSame([], $result->blockers);
    }

    #[Test]
    public function every_hard_constraint_is_reported_as_a_blocker(): void
    {
        $scenarios = [
            'wrong alliance' => [
                'friendly' => ['allianceId' => 999],
                'target' => [],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'wrong_alliance',
            ],
            'applicant' => [
                'friendly' => ['alliancePosition' => 'APPLICANT'],
                'target' => [],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'invalid_alliance_position',
            ],
            'unaffiliated position' => [
                'friendly' => ['alliancePosition' => 'noalliance'],
                'target' => [],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'invalid_alliance_position',
            ],
            'friendly vacation mode' => [
                'friendly' => ['vacationTurns' => 1],
                'target' => [],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'vacation_mode',
            ],
            'target vacation mode' => [
                'friendly' => [],
                'target' => ['vacationTurns' => 1],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'vacation_mode',
            ],
            'target beige' => [
                'friendly' => [],
                'target' => ['beigeTurns' => 1],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'target_beige',
            ],
            'out of range' => [
                'friendly' => [],
                'target' => ['score' => 2500.01],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'out_of_range',
            ],
            'offensive capacity exhausted' => [
                'friendly' => ['activeOffensiveWars' => 5],
                'target' => [],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'no_offensive_slot',
            ],
            'already attacking target' => [
                'friendly' => [],
                'target' => [],
                'already_attacking' => true,
                'conflict' => false,
                'code' => 'duplicate_war',
            ],
            'missing friendly military data' => [
                'friendly' => ['aircraft' => null],
                'target' => [],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'missing_military_data',
            ],
            'missing target military data' => [
                'friendly' => [],
                'target' => ['ships' => null],
                'already_attacking' => false,
                'conflict' => false,
                'code' => 'missing_military_data',
            ],
            'conflicting dispatch' => [
                'friendly' => [],
                'target' => [],
                'already_attacking' => false,
                'conflict' => true,
                'code' => 'conflicting_dispatched_assignment',
            ],
        ];

        foreach ($scenarios as $name => $scenario) {
            $friendly = $this->profile($scenario['friendly']);
            $target = $this->profile(['nationId' => 2, ...$scenario['target']]);
            $result = $this->evaluator->evaluate(
                $friendly,
                $target,
                [100],
                OperationType::Counter,
                $scenario['already_attacking'],
                $scenario['conflict'],
                $this->now,
            );

            $this->assertFalse($result->eligible(), $name);
            $this->assertSame(
                [$scenario['code']],
                array_column($result->blockers, 'code'),
                $name,
            );
            $friendlyMask = $this->evaluator->friendlyAllocationBlockerMask(
                $friendly,
                [100 => true],
                [],
                $scenario['conflict'],
            );
            $allocationMask = $this->evaluator->allocationBlockerMask(
                $friendly,
                $target,
                $scenario['already_attacking'],
                $friendlyMask,
            );
            $this->assertSame(
                [$scenario['code']],
                $this->evaluator->blockerCodes($allocationMask),
                $name.' allocation mask',
            );
        }
    }

    #[Test]
    public function stale_inactive_unlinked_and_loaded_nations_receive_independent_warnings(): void
    {
        $scenarios = [
            'stale snapshot' => [
                'friendly' => ['fetchedAt' => $this->now->modify('-16 minutes')],
                'code' => 'stale_snapshot',
            ],
            'inactive nation' => [
                'friendly' => ['lastActiveAt' => $this->now->modify('-73 hours')],
                'code' => 'inactive',
            ],
            'missing Discord link' => [
                'friendly' => ['discordLinked' => false],
                'code' => 'missing_discord_link',
            ],
            'existing reservation load' => [
                'friendly' => ['reservedOffensiveSlots' => 1],
                'code' => 'existing_load',
            ],
        ];

        foreach ($scenarios as $name => $scenario) {
            $result = $this->evaluator->evaluate(
                $this->profile($scenario['friendly']),
                $this->profile(['nationId' => 2]),
                [100],
                OperationType::Counter,
                at: $this->now,
            );

            $this->assertTrue($result->eligible(), $name);
            $this->assertSame([], $result->blockers, $name);
            $this->assertSame([$scenario['code']], array_column($result->warnings, 'code'), $name);
        }
    }

    #[Test]
    public function freshness_and_activity_thresholds_are_inclusive(): void
    {
        $result = $this->evaluator->evaluate(
            $this->profile([
                'fetchedAt' => $this->now->modify('-15 minutes'),
                'lastActiveAt' => $this->now->modify('-72 hours'),
            ]),
            $this->profile(['nationId' => 2]),
            [100],
            OperationType::Counter,
            at: $this->now,
        );

        $this->assertSame([], $result->warnings);
    }

    #[Test]
    public function eligibility_exposes_the_same_slot_math_used_for_the_hard_capacity_check(): void
    {
        $result = $this->evaluator->evaluate(
            $this->profile([
                'activeOffensiveWars' => 2,
                'reservedOffensiveSlots' => 1,
                'projects' => [
                    'pirate_economy' => true,
                    'advanced_pirate_economy' => true,
                ],
            ]),
            $this->profile(['nationId' => 2]),
            [100],
            OperationType::Counter,
            at: $this->now,
        );

        $this->assertSame([
            'base' => 5,
            'project_modifiers' => 2,
            'active_offensive_wars' => 2,
            'reservations' => 1,
            'available' => 4,
        ], $result->slotMath);
    }
}

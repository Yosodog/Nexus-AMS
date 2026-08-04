<?php

namespace App\Domain\Milcom;

use App\Domain\Milcom\Enums\OperationType;
use DateTimeImmutable;

final readonly class EligibilityEvaluator
{
    private const WRONG_ALLIANCE = 1 << 0;

    private const INVALID_ALLIANCE_POSITION = 1 << 1;

    private const VACATION_MODE = 1 << 2;

    private const TARGET_BEIGE = 1 << 3;

    private const OUT_OF_RANGE = 1 << 4;

    private const NO_OFFENSIVE_SLOT = 1 << 5;

    private const DUPLICATE_WAR = 1 << 6;

    private const MISSING_MILITARY_DATA = 1 << 7;

    private const CONFLICTING_DISPATCHED_ASSIGNMENT = 1 << 8;

    private const BLOCKER_CODES = [
        self::WRONG_ALLIANCE => 'wrong_alliance',
        self::INVALID_ALLIANCE_POSITION => 'invalid_alliance_position',
        self::VACATION_MODE => 'vacation_mode',
        self::TARGET_BEIGE => 'target_beige',
        self::OUT_OF_RANGE => 'out_of_range',
        self::NO_OFFENSIVE_SLOT => 'no_offensive_slot',
        self::DUPLICATE_WAR => 'duplicate_war',
        self::MISSING_MILITARY_DATA => 'missing_military_data',
        self::CONFLICTING_DISPATCHED_ASSIGNMENT => 'conflicting_dispatched_assignment',
    ];

    public function __construct(private MilcomGameRules $rules) {}

    /**
     * @param  list<int>  $allowedFriendlyAllianceIds
     * @param  list<int>  $allowedFriendlyNationIds
     */
    public function evaluate(
        ReadinessProfile $friendly,
        ReadinessProfile $target,
        array $allowedFriendlyAllianceIds,
        OperationType $operationType,
        bool $alreadyAttackingTarget = false,
        bool $hasConflictingDispatchedAssignment = false,
        ?DateTimeImmutable $at = null,
        array $allowedFriendlyNationIds = [],
    ): EligibilityResult {
        $at ??= new DateTimeImmutable;
        $allowedAllianceLookup = array_fill_keys($allowedFriendlyAllianceIds, true);
        $allowedNationLookup = array_fill_keys($allowedFriendlyNationIds, true);
        $friendlyBlockers = $this->friendlyAllocationBlockerMask(
            $friendly,
            $allowedAllianceLookup,
            $allowedNationLookup,
            $hasConflictingDispatchedAssignment,
        );
        $blockerMask = $this->allocationBlockerMask(
            $friendly,
            $target,
            $alreadyAttackingTarget,
            $friendlyBlockers,
        );
        $blockers = array_map(
            fn (string $code): array => $this->blockerFinding($code, $friendly),
            $this->blockerCodes($blockerMask),
        );
        $warnings = [];

        if ($this->rules->isSnapshotStale($friendly, $operationType, $at)
            || $this->rules->isSnapshotStale($target, $operationType, $at)) {
            $warnings[] = $this->finding(
                'stale_snapshot',
                'This nation or target has old data. Build the team again.'
            );
        }

        if ($friendly->lastActiveAt === null
            || ($at->getTimestamp() - $friendly->lastActiveAt->getTimestamp()) > 259200) {
            $warnings[] = $this->finding('inactive', 'This nation has no activity in the past 72 hours.');
        }

        if (! $friendly->discordLinked) {
            $warnings[] = $this->finding('missing_discord_link', 'This nation has no linked Discord account.');
        }

        if ($friendly->reservedOffensiveSlots > 0) {
            $warnings[] = $this->finding('existing_load', 'This nation is already on another Milcom team.');
        }

        return new EligibilityResult($blockers, $warnings, $this->rules->offensiveSlotMath($friendly));
    }

    /**
     * @param  array<int, true>  $allowedAllianceLookup
     * @param  array<int, true>  $allowedNationLookup
     */
    public function friendlyAllocationBlockerMask(
        ReadinessProfile $friendly,
        array $allowedAllianceLookup,
        array $allowedNationLookup,
        bool $hasConflictingDispatchedAssignment = false,
    ): int {
        $mask = 0;

        if (! isset($allowedNationLookup[$friendly->nationId])
            && ($friendly->allianceId === null || ! isset($allowedAllianceLookup[$friendly->allianceId]))) {
            $mask |= self::WRONG_ALLIANCE;
        }

        if (in_array(strtoupper($friendly->alliancePosition), ['APPLICANT', 'NOALLIANCE'], true)) {
            $mask |= self::INVALID_ALLIANCE_POSITION;
        }

        if ($friendly->vacationTurns > 0) {
            $mask |= self::VACATION_MODE;
        }

        if ($this->rules->availableOffensiveSlots($friendly) < 1) {
            $mask |= self::NO_OFFENSIVE_SLOT;
        }

        if (! $friendly->hasCompleteMilitaryData()) {
            $mask |= self::MISSING_MILITARY_DATA;
        }

        if ($hasConflictingDispatchedAssignment) {
            $mask |= self::CONFLICTING_DISPATCHED_ASSIGNMENT;
        }

        return $mask;
    }

    public function allocationBlockerMask(
        ReadinessProfile $friendly,
        ReadinessProfile $target,
        bool $alreadyAttackingTarget,
        int $friendlyBlockerMask,
    ): int {
        $mask = $friendlyBlockerMask;

        if ($target->vacationTurns > 0) {
            $mask |= self::VACATION_MODE;
        }

        if ((bool) config('milcom.game_rules.beige_blocks_declaration', true) && $target->beigeTurns > 0) {
            $mask |= self::TARGET_BEIGE;
        }

        if (! $this->rules->isInDeclarationRange($friendly, $target)) {
            $mask |= self::OUT_OF_RANGE;
        }

        if ($alreadyAttackingTarget) {
            $mask |= self::DUPLICATE_WAR;
        }

        if (! $target->hasCompleteMilitaryData()) {
            $mask |= self::MISSING_MILITARY_DATA;
        }

        return $mask;
    }

    /** @return list<string> */
    public function blockerCodes(int $mask): array
    {
        $codes = [];

        foreach (self::BLOCKER_CODES as $bit => $code) {
            if (($mask & $bit) !== 0) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @return array{code: string, message: string, context?: array<string, mixed>} */
    private function blockerFinding(string $code, ReadinessProfile $friendly): array
    {
        return match ($code) {
            'wrong_alliance' => $this->finding($code, 'This nation is not on the friendly list.'),
            'invalid_alliance_position' => $this->finding($code, 'Applicants and nations without an alliance cannot be assigned.'),
            'vacation_mode' => $this->finding($code, 'This nation or target is in vacation mode.'),
            'target_beige' => $this->finding($code, 'The target is on beige and cannot be declared on.'),
            'out_of_range' => $this->finding(
                $code,
                "The target is outside this nation's war range.",
                $this->rules->declarationRange($friendly),
            ),
            'no_offensive_slot' => $this->finding($code, 'This nation has no offensive slots left.'),
            'duplicate_war' => $this->finding($code, 'This nation is already at war with the target.'),
            'missing_military_data' => $this->finding($code, 'Military data is missing.'),
            'conflicting_dispatched_assignment' => $this->finding(
                $code,
                'This nation is already assigned to another target that was sent to Discord.',
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string, message: string, context?: array<string, mixed>}
     */
    private function finding(string $code, string $message, array $context = []): array
    {
        $finding = ['code' => $code, 'message' => $message];

        if ($context !== []) {
            $finding['context'] = $context;
        }

        return $finding;
    }
}

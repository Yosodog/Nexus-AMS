<?php

namespace App\Domain\Milcom\Allocation;

use App\Domain\Milcom\Enums\PriorityTier;
use InvalidArgumentException;

final class ScarcityFirstAllocator
{
    /**
     * Capacities include already locked reservations.
     *
     * @param  list<AllocationObjective>  $objectives
     * @param  list<CandidateEdge>  $candidateEdges
     * @param  array<int, int>  $capacityByNation
     */
    public function allocate(
        array $objectives,
        array $candidateEdges,
        array $capacityByNation,
    ): AllocationResult {
        $objectiveById = [];

        foreach ($objectives as $objective) {
            $objectiveById[$objective->id] = $objective;
        }

        return $this->allocatePrepared(
            $objectives,
            $this->prepareEdges($candidateEdges, $objectiveById),
            $capacityByNation,
        );
    }

    /**
     * Allocate candidate edges that are already grouped, sorted, and capped per objective.
     *
     * @param  list<AllocationObjective>  $objectives
     * @param  array<int, CandidatePool|list<CandidateEdge>>  $edgesByObjective
     * @param  array<int, int>  $capacityByNation
     */
    public function allocatePrepared(
        array $objectives,
        array $edgesByObjective,
        array $capacityByNation,
    ): AllocationResult {
        $objectiveById = [];
        $assignments = [];
        $loads = [];
        $lockedPairs = [];

        foreach ($objectives as $objective) {
            $objectiveById[$objective->id] = $objective;
            $assignments[$objective->id] = [];

            foreach ($objective->lockedNationIds as $nationId) {
                $capacity = (int) ($capacityByNation[$nationId] ?? 0);
                $loads[$nationId] = (int) ($loads[$nationId] ?? 0) + 1;

                if ($loads[$nationId] > $capacity) {
                    throw new InvalidArgumentException(
                        "Locked team spots exceed offensive capacity for nation {$nationId}."
                    );
                }

                $assignments[$objective->id][$nationId] = [
                    'nation_id' => $nationId,
                    'score' => 0.0,
                    'confidence' => 0.0,
                    'locked' => true,
                ];
                $lockedPairs[$this->pairKey($objective->id, $nationId)] = true;
            }
        }

        foreach ($edgesByObjective as $objectiveId => $edges) {
            foreach ($edges as $edge) {
                if (isset($assignments[$objectiveId][$edge->nationId])) {
                    $assignments[$objectiveId][$edge->nationId]['score'] = $edge->score;
                    $assignments[$objectiveId][$edge->nationId]['confidence'] = $edge->confidence;
                }
            }
        }

        $ordered = array_values($objectiveById);
        usort($ordered, fn (AllocationObjective $left, AllocationObjective $right): int => $this->compareObjectives(
            $left,
            $right,
            $edgesByObjective
        ));

        foreach ([PriorityTier::Critical, PriorityTier::High, PriorityTier::Standard] as $tier) {
            foreach ($ordered as $objective) {
                if ($objective->tier !== $tier) {
                    continue;
                }

                $this->fillToDepth(
                    $objective,
                    $objective->minimumDepth,
                    $assignments,
                    $loads,
                    $capacityByNation,
                    $edgesByObjective
                );
            }
        }

        $this->repairCriticalMinimums(
            $ordered,
            $assignments,
            $loads,
            $capacityByNation,
            $edgesByObjective,
            $objectiveById,
            $lockedPairs
        );

        foreach ([PriorityTier::Critical, PriorityTier::High, PriorityTier::Standard] as $tier) {
            foreach ($ordered as $objective) {
                if ($objective->tier !== $tier) {
                    continue;
                }

                $this->fillToDepth(
                    $objective,
                    $objective->desiredDepth,
                    $assignments,
                    $loads,
                    $capacityByNation,
                    $edgesByObjective
                );
            }
        }

        $this->improveWithDeterministicSwaps(
            $ordered,
            $assignments,
            $edgesByObjective,
            $objectiveById,
            $lockedPairs
        );

        $unfilledMinimum = [];
        $unfilledDesired = [];
        $result = [];

        ksort($assignments);

        foreach ($assignments as $objectiveId => $objectiveAssignments) {
            $objective = $objectiveById[$objectiveId];
            $rows = array_values($objectiveAssignments);
            usort($rows, static fn (array $left, array $right): int => [
                ! $right['locked'],
                $right['score'],
                $left['nation_id'],
            ] <=> [
                ! $left['locked'],
                $left['score'],
                $right['nation_id'],
            ]);
            $result[$objectiveId] = $rows;
            $count = count($rows);

            if ($count < $objective->minimumDepth) {
                $unfilledMinimum[$objectiveId] = $objective->minimumDepth - $count;
            }

            if ($count < $objective->desiredDepth) {
                $unfilledDesired[$objectiveId] = $objective->desiredDepth - $count;
            }
        }

        return new AllocationResult($result, $unfilledMinimum, $unfilledDesired);
    }

    /**
     * @param  list<CandidateEdge>  $candidateEdges
     * @param  array<int, AllocationObjective>  $objectiveById
     * @return array<int, list<CandidateEdge>>
     */
    private function prepareEdges(array $candidateEdges, array $objectiveById): array
    {
        $grouped = [];

        foreach ($candidateEdges as $edge) {
            if (! isset($objectiveById[$edge->objectiveId])) {
                continue;
            }

            $grouped[$edge->objectiveId][] = $edge;
        }

        $limit = (int) config('milcom.doctrine.candidate_limit_per_objective', 40);

        foreach ($grouped as $objectiveId => $edges) {
            usort($edges, static fn (CandidateEdge $left, CandidateEdge $right): int => [
                $right->score,
                $right->confidence,
                $left->nationId,
            ] <=> [
                $left->score,
                $left->confidence,
                $right->nationId,
            ]);
            $grouped[$objectiveId] = array_slice($edges, 0, $limit);
        }

        return $grouped;
    }

    /**
     * @param  array<int, CandidatePool|list<CandidateEdge>>  $edgesByObjective
     */
    private function compareObjectives(
        AllocationObjective $left,
        AllocationObjective $right,
        array $edgesByObjective,
    ): int {
        return [
            $left->tier->order(),
            count($edgesByObjective[$left->id] ?? []),
            $left->id,
        ] <=> [
            $right->tier->order(),
            count($edgesByObjective[$right->id] ?? []),
            $right->id,
        ];
    }

    /**
     * @param  array<int, array<int, array{nation_id: int, score: float, confidence: float, locked: bool}>>  $assignments
     * @param  array<int, int>  $loads
     * @param  array<int, int>  $capacityByNation
     * @param  array<int, CandidatePool|list<CandidateEdge>>  $edgesByObjective
     */
    private function fillToDepth(
        AllocationObjective $objective,
        int $depth,
        array &$assignments,
        array &$loads,
        array $capacityByNation,
        array $edgesByObjective,
    ): void {
        if ($objective->tier === PriorityTier::Hold) {
            return;
        }

        while (count($assignments[$objective->id]) < $depth) {
            $edge = $this->bestAvailableEdge(
                $objective->id,
                $assignments,
                $loads,
                $capacityByNation,
                $edgesByObjective
            );

            if ($edge === null) {
                return;
            }

            $this->assign($edge, $assignments, $loads);
        }
    }

    /**
     * @param  array<int, array<int, array{nation_id: int, score: float, confidence: float, locked: bool}>>  $assignments
     * @param  array<int, int>  $loads
     * @param  array<int, int>  $capacityByNation
     * @param  array<int, CandidatePool|list<CandidateEdge>>  $edgesByObjective
     */
    private function bestAvailableEdge(
        int $objectiveId,
        array $assignments,
        array $loads,
        array $capacityByNation,
        array $edgesByObjective,
    ): ?CandidateEdge {
        $best = null;
        $bestUtility = -INF;

        foreach ($edgesByObjective[$objectiveId] ?? [] as $edge) {
            if (isset($assignments[$objectiveId][$edge->nationId])
                || (int) ($loads[$edge->nationId] ?? 0) >= (int) ($capacityByNation[$edge->nationId] ?? 0)) {
                continue;
            }

            $utility = $edge->score - ((int) ($loads[$edge->nationId] ?? 0) * 2);

            if ($best === null
                || $utility > $bestUtility
                || ($utility === $bestUtility && $edge->confidence > $best->confidence)
                || ($utility === $bestUtility
                    && $edge->confidence === $best->confidence
                    && $edge->nationId < $best->nationId)) {
                $best = $edge;
                $bestUtility = $utility;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, array<int, array{nation_id: int, score: float, confidence: float, locked: bool}>>  $assignments
     * @param  array<int, int>  $loads
     */
    private function assign(CandidateEdge $edge, array &$assignments, array &$loads): void
    {
        $assignments[$edge->objectiveId][$edge->nationId] = [
            'nation_id' => $edge->nationId,
            'score' => $edge->score,
            'confidence' => $edge->confidence,
            'locked' => false,
        ];
        $loads[$edge->nationId] = (int) ($loads[$edge->nationId] ?? 0) + 1;
    }

    /**
     * @param  list<AllocationObjective>  $ordered
     * @param  array<int, array<int, array{nation_id: int, score: float, confidence: float, locked: bool}>>  $assignments
     * @param  array<int, int>  $loads
     * @param  array<int, int>  $capacityByNation
     * @param  array<int, CandidatePool|list<CandidateEdge>>  $edgesByObjective
     * @param  array<int, AllocationObjective>  $objectiveById
     * @param  array<string, bool>  $lockedPairs
     */
    private function repairCriticalMinimums(
        array $ordered,
        array &$assignments,
        array &$loads,
        array $capacityByNation,
        array $edgesByObjective,
        array $objectiveById,
        array $lockedPairs,
    ): void {
        $repairs = 0;
        $objectiveIdsByNation = $this->objectiveIdsByNation($assignments);

        foreach ($ordered as $critical) {
            if ($critical->tier !== PriorityTier::Critical) {
                continue;
            }

            while (count($assignments[$critical->id]) < $critical->minimumDepth && $repairs < 200) {
                $repaired = false;

                foreach ($edgesByObjective[$critical->id] ?? [] as $criticalEdge) {
                    if (isset($assignments[$critical->id][$criticalEdge->nationId])) {
                        continue;
                    }

                    if ((int) ($loads[$criticalEdge->nationId] ?? 0)
                        < (int) ($capacityByNation[$criticalEdge->nationId] ?? 0)) {
                        $this->assign($criticalEdge, $assignments, $loads);
                        $this->addNationObjective(
                            $objectiveIdsByNation,
                            $criticalEdge->nationId,
                            $critical->id,
                        );
                        $repaired = true;
                        break;
                    }

                    foreach ($objectiveIdsByNation[$criticalEdge->nationId] ?? [] as $donorId) {
                        $donor = $objectiveById[$donorId];

                        if (! isset($assignments[$donor->id][$criticalEdge->nationId])
                            || isset($lockedPairs[$this->pairKey($donor->id, $criticalEdge->nationId)])
                            || $donor->tier->order() < $critical->tier->order()) {
                            continue;
                        }

                        $donorCanLose = count($assignments[$donor->id]) > $donor->minimumDepth;
                        $replacement = $this->bestAvailableEdge(
                            $donor->id,
                            $assignments,
                            $loads,
                            $capacityByNation,
                            $edgesByObjective
                        );

                        if (! $donorCanLose && $replacement === null) {
                            continue;
                        }

                        unset($assignments[$donor->id][$criticalEdge->nationId]);
                        $this->removeNationObjective(
                            $objectiveIdsByNation,
                            $criticalEdge->nationId,
                            $donor->id,
                        );
                        $loads[$criticalEdge->nationId]--;

                        if ($replacement !== null) {
                            $this->assign($replacement, $assignments, $loads);
                            $this->addNationObjective(
                                $objectiveIdsByNation,
                                $replacement->nationId,
                                $donor->id,
                            );
                        }

                        $this->assign($criticalEdge, $assignments, $loads);
                        $this->addNationObjective(
                            $objectiveIdsByNation,
                            $criticalEdge->nationId,
                            $critical->id,
                        );
                        $repaired = true;
                        break 2;
                    }
                }

                $repairs++;

                if (! $repaired) {
                    break;
                }
            }
        }
    }

    /**
     * @param  list<AllocationObjective>  $ordered
     * @param  array<int, array<int, array{nation_id: int, score: float, confidence: float, locked: bool}>>  $assignments
     * @param  array<int, CandidatePool|list<CandidateEdge>>  $edgesByObjective
     * @param  array<int, AllocationObjective>  $objectiveById
     * @param  array<string, bool>  $lockedPairs
     */
    private function improveWithDeterministicSwaps(
        array $ordered,
        array &$assignments,
        array $edgesByObjective,
        array $objectiveById,
        array $lockedPairs,
    ): void {
        $swaps = 0;
        $objectiveIdsByNation = $this->objectiveIdsByNation($assignments);

        foreach ($ordered as $objective) {
            foreach (array_keys($assignments[$objective->id]) as $currentNationId) {
                if ($swaps >= 200 || isset($lockedPairs[$this->pairKey($objective->id, $currentNationId)])) {
                    continue;
                }

                $currentEdge = $this->edgeForNation(
                    $objective->id,
                    $currentNationId,
                    $edgesByObjective,
                );

                if ($currentEdge === null) {
                    continue;
                }

                foreach ($edgesByObjective[$objective->id] ?? [] as $candidate) {
                    if ($candidate->score <= $currentEdge->score
                        || isset($assignments[$objective->id][$candidate->nationId])) {
                        continue;
                    }

                    foreach ($objectiveIdsByNation[$candidate->nationId] ?? [] as $donorId) {
                        $donorAssignments = $assignments[$donorId];

                        if (! isset($donorAssignments[$candidate->nationId])
                            || isset($lockedPairs[$this->pairKey($donorId, $candidate->nationId)])
                            || $objectiveById[$donorId]->tier->order() < $objective->tier->order()) {
                            continue;
                        }

                        $reverseEdge = $this->edgeForNation($donorId, $currentNationId, $edgesByObjective);
                        $donorCurrent = $this->edgeForNation($donorId, $candidate->nationId, $edgesByObjective);

                        if ($reverseEdge === null || $donorCurrent === null) {
                            continue;
                        }

                        if (($candidate->score + $reverseEdge->score)
                            <= ($currentEdge->score + $donorCurrent->score)) {
                            continue;
                        }

                        unset(
                            $assignments[$objective->id][$currentNationId],
                            $assignments[$donorId][$candidate->nationId]
                        );
                        $assignments[$objective->id][$candidate->nationId] = [
                            'nation_id' => $candidate->nationId,
                            'score' => $candidate->score,
                            'confidence' => $candidate->confidence,
                            'locked' => false,
                        ];
                        $assignments[$donorId][$currentNationId] = [
                            'nation_id' => $currentNationId,
                            'score' => $reverseEdge->score,
                            'confidence' => $reverseEdge->confidence,
                            'locked' => false,
                        ];
                        $this->removeNationObjective(
                            $objectiveIdsByNation,
                            $currentNationId,
                            $objective->id,
                        );
                        $this->removeNationObjective(
                            $objectiveIdsByNation,
                            $candidate->nationId,
                            $donorId,
                        );
                        $this->addNationObjective(
                            $objectiveIdsByNation,
                            $candidate->nationId,
                            $objective->id,
                        );
                        $this->addNationObjective(
                            $objectiveIdsByNation,
                            $currentNationId,
                            $donorId,
                        );
                        $swaps++;
                        break 2;
                    }
                }
            }
        }
    }

    /**
     * @param  array<int, CandidatePool|list<CandidateEdge>>  $edgesByObjective
     */
    private function edgeForNation(int $objectiveId, int $nationId, array $edgesByObjective): ?CandidateEdge
    {
        $edges = $edgesByObjective[$objectiveId] ?? [];

        if ($edges instanceof CandidatePool) {
            return $edges->findNation($nationId);
        }

        foreach ($edges as $edge) {
            if ($edge->nationId === $nationId) {
                return $edge;
            }
        }

        return null;
    }

    private function pairKey(int $objectiveId, int $nationId): string
    {
        return "{$objectiveId}:{$nationId}";
    }

    /**
     * @param  array<int, array<int, array{nation_id: int, score: float, confidence: float, locked: bool}>>  $assignments
     * @return array<int, list<int>>
     */
    private function objectiveIdsByNation(array $assignments): array
    {
        $lookup = [];

        foreach ($assignments as $objectiveId => $objectiveAssignments) {
            foreach (array_keys($objectiveAssignments) as $nationId) {
                $lookup[$nationId][] = (int) $objectiveId;
            }
        }

        foreach ($lookup as &$objectiveIds) {
            sort($objectiveIds);
        }

        return $lookup;
    }

    /** @param array<int, list<int>> $lookup */
    private function addNationObjective(array &$lookup, int $nationId, int $objectiveId): void
    {
        if (in_array($objectiveId, $lookup[$nationId] ?? [], true)) {
            return;
        }

        $lookup[$nationId][] = $objectiveId;
        sort($lookup[$nationId]);
    }

    /** @param array<int, list<int>> $lookup */
    private function removeNationObjective(array &$lookup, int $nationId, int $objectiveId): void
    {
        $lookup[$nationId] = array_values(array_filter(
            $lookup[$nationId] ?? [],
            static fn (int $candidate): bool => $candidate !== $objectiveId,
        ));

        if ($lookup[$nationId] === []) {
            unset($lookup[$nationId]);
        }
    }
}

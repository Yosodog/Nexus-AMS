<?php

namespace App\Domain\Milcom\Allocation;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A compact, ordered candidate list for one objective.
 *
 * Scores and confidence are stored as unsigned hundredths. Each candidate
 * occupies eight bytes instead of retaining a PHP object for every pairing.
 *
 * @implements IteratorAggregate<int, CandidateEdge>
 */
final class CandidatePool implements Countable, IteratorAggregate
{
    private const RECORD_BYTES = 8;

    private function __construct(
        public readonly int $objectiveId,
        private readonly string $records,
        private readonly int $candidateCount,
    ) {}

    public static function empty(int $objectiveId): self
    {
        return new self($objectiveId, '', 0);
    }

    /** @param iterable<CandidateEdge> $edges */
    public static function fromEdges(int $objectiveId, iterable $edges): self
    {
        $records = '';
        $count = 0;

        foreach ($edges as $edge) {
            if ($edge->objectiveId !== $objectiveId) {
                continue;
            }

            $records .= pack(
                'Vvv',
                $edge->nationId,
                self::encodePercent($edge->score),
                self::encodePercent($edge->confidence),
            );
            $count++;
        }

        return new self($objectiveId, $records, $count);
    }

    public function count(): int
    {
        return $this->candidateCount;
    }

    /** @return Traversable<int, CandidateEdge> */
    public function getIterator(): Traversable
    {
        for ($index = 0; $index < $this->candidateCount; $index++) {
            yield $index => $this->edgeAt($index);
        }
    }

    public function findNation(int $nationId): ?CandidateEdge
    {
        for ($index = 0; $index < $this->candidateCount; $index++) {
            $edge = $this->edgeAt($index);

            if ($edge->nationId === $nationId) {
                return $edge;
            }
        }

        return null;
    }

    private function edgeAt(int $index): CandidateEdge
    {
        /** @var array{nation_id: int, score: int, confidence: int} $record */
        $record = unpack(
            'Vnation_id/vscore/vconfidence',
            $this->records,
            $index * self::RECORD_BYTES,
        );

        return new CandidateEdge(
            objectiveId: $this->objectiveId,
            nationId: $record['nation_id'],
            score: $record['score'] / 100,
            confidence: $record['confidence'] / 100,
        );
    }

    private static function encodePercent(float $value): int
    {
        return (int) round(max(0, min(100, $value)) * 100);
    }
}

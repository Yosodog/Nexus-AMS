<?php

namespace App\Domain\Milcom;

use JsonSerializable;

final readonly class PairAssessment implements JsonSerializable
{
    /**
     * @param  array{air: float, ground: float, naval: float, readiness: float, tactical_fit: float, activity: float}  $factors
     * @param  list<array{code: string, message: string}>  $warnings
     * @param  array<string, mixed>  $explanation
     */
    public function __construct(
        public int $friendlyNationId,
        public int $targetNationId,
        public float $score,
        public float $confidence,
        public array $factors,
        public array $warnings,
        public array $explanation,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'friendly_nation_id' => $this->friendlyNationId,
            'target_nation_id' => $this->targetNationId,
            'score' => $this->score,
            'confidence' => $this->confidence,
            'factors' => $this->factors,
            'warnings' => $this->warnings,
            'explanation' => $this->explanation,
        ];
    }
}

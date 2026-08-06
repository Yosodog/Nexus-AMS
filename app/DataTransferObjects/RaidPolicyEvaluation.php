<?php

namespace App\DataTransferObjects;

final readonly class RaidPolicyEvaluation
{
    /**
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $reasons
     */
    public function __construct(
        public bool $allowed,
        public int $topAllianceCap,
        public array $reasons,
    ) {}

    /**
     * @return array{allowed: bool, top_alliance_cap: int, reasons: list<array{code: string, message: string, context: array<string, mixed>}>}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'top_alliance_cap' => $this->topAllianceCap,
            'reasons' => $this->reasons,
        ];
    }
}

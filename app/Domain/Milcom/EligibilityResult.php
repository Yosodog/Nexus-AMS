<?php

namespace App\Domain\Milcom;

use JsonSerializable;

final readonly class EligibilityResult implements JsonSerializable
{
    /**
     * @param  list<array{code: string, message: string, context?: array<string, mixed>}>  $blockers
     * @param  list<array{code: string, message: string, context?: array<string, mixed>}>  $warnings
     * @param  array<string, int|float|string|bool|null>  $slotMath
     */
    public function __construct(
        public array $blockers,
        public array $warnings,
        public array $slotMath,
    ) {}

    public function eligible(): bool
    {
        return $this->blockers === [];
    }

    public function jsonSerialize(): array
    {
        return [
            'eligible' => $this->eligible(),
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'slot_math' => $this->slotMath,
        ];
    }
}

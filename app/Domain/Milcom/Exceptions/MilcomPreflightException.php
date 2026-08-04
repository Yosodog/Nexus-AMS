<?php

namespace App\Domain\Milcom\Exceptions;

use RuntimeException;

class MilcomPreflightException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $blockers
     * @param  list<array<string, mixed>>  $warnings
     */
    public function __construct(
        public readonly array $blockers,
        public readonly array $warnings = [],
    ) {
        parent::__construct('The final checks failed.');
    }
}

<?php

namespace App\Domain\Milcom\Exceptions;

use RuntimeException;

class StaleGenerationException extends RuntimeException
{
    public function __construct(
        public readonly int $expectedGeneration,
        public readonly int $currentGeneration,
    ) {
        parent::__construct('This plan changed since you loaded the page.');
    }
}

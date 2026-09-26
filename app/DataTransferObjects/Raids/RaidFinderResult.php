<?php

namespace App\DataTransferObjects\Raids;

/**
 * Ranked raid targets and response metadata.
 */
final readonly class RaidFinderResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $rows,
        public array $meta,
    ) {}
}

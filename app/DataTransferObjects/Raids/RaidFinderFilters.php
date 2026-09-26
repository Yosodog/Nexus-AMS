<?php

namespace App\DataTransferObjects\Raids;

/**
 * Raid finder query options.
 */
final readonly class RaidFinderFilters
{
    public const SCOPE_ANY = 'any';

    public const SCOPE_UNALIGNED = 'unaligned';

    public const SCOPE_ALIGNED = 'aligned';

    public function __construct(
        public int $limit = 50,
        public ?float $minExpectedNet = null,
        public ?int $minInactiveDays = null,
        public int $beigeWithinTurns = 0,
        public string $allianceScope = self::SCOPE_ANY,
        public bool $beatableOnly = false,
        public bool $hideClaimed = false,
        public bool $fresh = false,
    ) {}

    /**
     * Cache identity of every option that changes the ranked rows.
     */
    public function cacheKey(): string
    {
        return sha1((string) json_encode([
            $this->limit,
            $this->minExpectedNet,
            $this->minInactiveDays,
            $this->beigeWithinTurns,
            $this->allianceScope,
            $this->beatableOnly,
            $this->hideClaimed,
        ]));
    }
}

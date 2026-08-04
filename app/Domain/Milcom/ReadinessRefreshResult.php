<?php

namespace App\Domain\Milcom;

use Carbon\CarbonInterface;

class ReadinessRefreshResult
{
    /**
     * @param  list<int>  $refreshedNationIds
     * @param  list<int>  $missingNationIds
     */
    public function __construct(
        public readonly CarbonInterface $fetchedAt,
        array $refreshedNationIds,
        array $missingNationIds,
    ) {
        $this->refreshedNationIds = $this->normalize($refreshedNationIds);
        $this->missingNationIds = $this->normalize($missingNationIds);
    }

    /** @var list<int> */
    private readonly array $refreshedNationIds;

    /** @var list<int> */
    private readonly array $missingNationIds;

    /**
     * @param  list<int>  $nationIds
     * @return list<int>
     */
    public function refreshedFrom(array $nationIds): array
    {
        return array_values(array_intersect(
            $this->normalize($nationIds),
            $this->refreshedNationIds,
        ));
    }

    /**
     * @param  list<int>  $nationIds
     * @return list<int>
     */
    public function missingFrom(array $nationIds): array
    {
        return array_values(array_intersect(
            $this->normalize($nationIds),
            $this->missingNationIds,
        ));
    }

    /**
     * @param  list<int>  $nationIds
     * @return list<int>
     */
    private function normalize(array $nationIds): array
    {
        return array_values(array_unique(array_map('intval', $nationIds)));
    }
}

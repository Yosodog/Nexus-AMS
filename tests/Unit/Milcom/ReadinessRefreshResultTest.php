<?php

namespace Tests\Unit\Milcom;

use App\Domain\Milcom\ReadinessRefreshResult;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReadinessRefreshResultTest extends TestCase
{
    public function test_it_separates_refreshed_friendlies_from_missing_required_targets(): void
    {
        $fetchedAt = CarbonImmutable::parse('2026-08-02 22:00:00 UTC');
        $result = new ReadinessRefreshResult(
            fetchedAt: $fetchedAt,
            refreshedNationIds: [10, 20, 20, 30],
            missingNationIds: [40, 50, 50],
        );

        $this->assertSame([10, 30], $result->refreshedFrom([10, 40, 30]));
        $this->assertSame([40], $result->missingFrom([30, 40]));
        $this->assertSame([], $result->missingFrom([10, 20]));
        $this->assertSame($fetchedAt, $result->fetchedAt);
    }
}

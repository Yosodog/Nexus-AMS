<?php

namespace Tests\Unit\Services;

use App\Services\ApiDateNormalizer;
use Tests\UnitTestCase;

class ApiDateNormalizerTest extends UnitTestCase
{
    public function test_normalize_date_rejects_blank_negative_and_zero_dates(): void
    {
        $this->assertNull(ApiDateNormalizer::normalizeDate(null));
        $this->assertNull(ApiDateNormalizer::normalizeDate(''));
        $this->assertNull(ApiDateNormalizer::normalizeDate('-0001-11-30'));
        $this->assertNull(ApiDateNormalizer::normalizeDate('0000-00-00'));
    }

    public function test_normalize_date_and_timestamp_return_stable_utc_values(): void
    {
        $this->assertSame('2026-06-01', ApiDateNormalizer::normalizeDate('2026-06-01T22:15:00+00:00'));
        $this->assertSame('2026-06-01 22:15:00', ApiDateNormalizer::normalizeTimestamp('2026-06-01T22:15:00+00:00'));
        $this->assertSame('2026-06-01 17:15:00', ApiDateNormalizer::normalizeTimestamp('2026-06-01T22:15:00+00:00', 'America/Chicago'));
    }
}

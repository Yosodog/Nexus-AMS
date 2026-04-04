<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

class ApiDateNormalizer
{
    public static function normalizeDate(mixed $value): ?string
    {
        $normalized = self::normalizeRawValue($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($normalized, 'UTC')->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    public static function normalizeTimestamp(mixed $value, ?string $timezone = null): ?string
    {
        $normalized = self::normalizeRawValue($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($normalized, 'UTC')
                ->setTimezone($timezone ?? config('app.timezone'))
                ->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private static function normalizeRawValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $dateValue = trim((string) $value);

        if ($dateValue === '' || str_starts_with($dateValue, '-') || str_starts_with($dateValue, '0000-00-00')) {
            return null;
        }

        return $dateValue;
    }
}

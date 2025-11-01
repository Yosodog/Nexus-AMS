<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PWHealthService
{
    public const CACHE_KEY_STATUS = 'pw:health:status';       // bool: true=up, false=down

    public const CACHE_KEY_CHECKED_AT = 'pw:health:checked_at';

    public const CACHE_KEY_LAST_ERROR = 'pw:health:last_error';

    public function __construct(
        private readonly QueryService $query,
    ) {}

    /**
     * Run the actual health check using the exact 'me' query and cache the result.
     */
    public function checkAndCache(int $ttlSeconds = 600): bool
    {
        try {
            $builder = (new \App\Services\GraphQLQueryBuilder)
                ->setRootField('me')
                ->addFields([
                    'requests',
                    'max_requests',
                    'key',
                    'permission_bits',
                ])
                ->addNestedField('nation', function (\App\Services\GraphQLQueryBuilder $b) {
                    $b->addFields(['leader_name']);
                });

            // No pagination, no special headers needed
            $data = $this->query->sendQuery($builder, headers: false, handlePagination: false);

            // Basic sanity: we expect certain keys to exist
            $ok = isset($data->requests, $data->max_requests, $data->key, $data->permission_bits);

            Cache::put(self::CACHE_KEY_STATUS, $ok, $ttlSeconds);
            Cache::put(self::CACHE_KEY_CHECKED_AT, now()->toIso8601String(), $ttlSeconds);
            Cache::forget(self::CACHE_KEY_LAST_ERROR);

            return $ok;
        } catch (Throwable $e) {
            Log::warning('PW API health check FAILED: '.$e->getMessage());
            Cache::put(self::CACHE_KEY_STATUS, false, $ttlSeconds);
            Cache::put(self::CACHE_KEY_CHECKED_AT, now()->toIso8601String(), $ttlSeconds);
            Cache::put(self::CACHE_KEY_LAST_ERROR, $e->getMessage(), $ttlSeconds);

            return false;
        }
    }

    /**
     * Read-only fast path from cache. If missing, do a quick check.
     */
    public function isUp(int $fallbackCheckTtl = 600, bool $checkIfEmpty = false): bool
    {
        $cached = Cache::get(self::CACHE_KEY_STATUS);

        if (is_bool($cached)) {
            return $cached;
        }

        return $checkIfEmpty
            ? $this->checkAndCache($fallbackCheckTtl)
            : true;
    }

    public function isDown(): bool
    {
        return ! $this->isUp();
    }

    public function lastCheckedAt(): ?string
    {
        return Cache::get(self::CACHE_KEY_CHECKED_AT);
    }

    public function lastError(): ?string
    {
        return Cache::get(self::CACHE_KEY_LAST_ERROR);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class RaidFinderCache
{
    private const POLICY_VERSION_KEY = 'raid-finder:policy-version';

    public function key(int $nationId): string
    {
        return sprintf('raid-finder:v%d:%d', $this->policyVersion(), $nationId);
    }

    public function invalidatePolicy(): void
    {
        if (Cache::add(self::POLICY_VERSION_KEY, 2, now()->addYears(10))) {
            return;
        }

        Cache::increment(self::POLICY_VERSION_KEY);
    }

    private function policyVersion(): int
    {
        return max((int) Cache::get(self::POLICY_VERSION_KEY, 1), 1);
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use Illuminate\Support\Facades\Cache;

class PendingRequestsService
{
    public const CACHE_KEY = 'pending_requests.counts';

    public function __construct(private readonly StaffWorkQueueRegistry $registry) {}

    /**
     * Retrieve cached counts for every source that loaded successfully.
     *
     * @return array<string, int>
     */
    public function getRawCounts(): array
    {
        $counts = $this->registry->snapshot()['counts'];
        $this->mirrorLegacyCounts($counts);

        return $counts;
    }

    /**
     * Get pending counts the user is permitted to manage, including projection health.
     *
     * @return array{
     *     counts: array<string, int>,
     *     total: int,
     *     complete: bool,
     *     can_view: bool,
     *     unavailable: array<string, array{label: string}>,
     *     generated_at: string
     * }
     */
    public function getCountsForUser(User $user): array
    {
        $projection = $this->registry->forUser($user);

        if ($projection['types'] !== []) {
            $this->mirrorLegacyCounts($this->registry->snapshot()['counts']);
        }

        return [
            'counts' => $projection['counts'],
            'total' => $projection['total'],
            'complete' => $projection['complete'],
            'can_view' => $projection['types'] !== [],
            'unavailable' => $projection['failures'],
            'generated_at' => $projection['generated_at'],
        ];
    }

    public function flushCache(): void
    {
        $this->registry->flushCache();
        Cache::forget($this->legacyCacheKey());
    }

    /**
     * Keep the long-standing count cache shape readable during rolling deployments.
     *
     * @param  array<string, int>  $counts
     */
    private function mirrorLegacyCounts(array $counts): void
    {
        Cache::put(
            $this->legacyCacheKey(),
            $counts,
            now()->addSeconds($this->cacheTtl()),
        );
    }

    private function legacyCacheKey(): string
    {
        return (string) config('pending_requests.cache_key', self::CACHE_KEY);
    }

    private function cacheTtl(): int
    {
        $configured = (int) config('pending_requests.cache_ttl_seconds', 900);

        return min(max($configured, 600), 1800);
    }
}

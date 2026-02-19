<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class PendingRequestsService
{
    public const CACHE_KEY = 'pending_requests.counts';

    public function __construct(
        private readonly LoanService $loanService,
        private readonly WarAidService $warAidService,
        private readonly RebuildingService $rebuildingService,
    ) {}

    /**
     * Retrieve cached counts for all pending request types without filtering.
     */
    public function getRawCounts(): array
    {
        $cacheKey = $this->cacheKey();

        return Cache::remember(
            $cacheKey,
            now()->addSeconds($this->cacheTtl()),
            fn () => $this->buildCounts()
        );
    }

    /**
     * Get pending counts the user is permitted to manage, including a total.
     */
    public function getCountsForUser(User $user): array
    {
        $rawCounts = $this->getRawCounts();
        $filteredCounts = $this->filterCountsForUser($user, $rawCounts);

        return [
            'counts' => $filteredCounts,
            'total' => array_sum($filteredCounts),
        ];
    }

    public function flushCache(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @return array<int|string, mixed>
     */
    private function buildCounts(): array
    {
        return [
            'withdrawals' => TransactionService::countPendingWithdrawals(),
            'city_grants' => CityGrantService::countPending(),
            'grants' => GrantService::countPending(),
            'loans' => $this->loanService->countPending(),
            'war_aid' => $this->warAidService->countPending(),
            'rebuilding' => $this->rebuildingService->countPending(),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function filterCountsForUser(User $user, array $rawCounts): array
    {
        $permissions = $this->permissionsMap();
        $gate = Gate::forUser($user);
        $canManageAll = (bool) ($user->is_admin ?? false);

        return collect($rawCounts)
            ->mapWithKeys(function ($count, $type) use ($permissions, $gate) {
                $ability = $permissions[$type] ?? null;

                if ($ability && $gate->allows($ability)) {
                    return [$type => (int) $count];
                }

                return [];
            })
            ->when($canManageAll, fn ($collection) => collect($rawCounts)->mapWithKeys(
                fn ($count, $type) => [$type => (int) $count]
            ))
            ->all();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function permissionsMap(): array
    {
        return config('pending_requests.permissions', []);
    }

    private function cacheKey(): string
    {
        return config('pending_requests.cache_key', self::CACHE_KEY);
    }

    private function cacheTtl(): int
    {
        $configuredTtl = (int) config('pending_requests.cache_ttl_seconds', 900);

        return min(max($configuredTtl, 600), 1800);
    }
}

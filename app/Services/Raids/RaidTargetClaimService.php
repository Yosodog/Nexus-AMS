<?php

namespace App\Services\Raids;

use App\Models\RaidTargetClaim;
use Illuminate\Support\Collection;

/**
 * Single-active-claim workflow that lets members reserve a raid target.
 */
final class RaidTargetClaimService
{
    /**
     * Active, unexpired claims for the given targets, keyed by target nation id.
     *
     * @param  list<int>  $targetIds
     * @return Collection<int, RaidTargetClaim>
     */
    public function activeForTargets(array $targetIds): Collection
    {
        if ($targetIds === []) {
            return collect();
        }

        $claims = (new RaidTargetClaim)->getTable();

        return RaidTargetClaim::query()
            ->leftJoin('nations', 'nations.id', '=', $claims.'.nation_id')
            ->select([$claims.'.*', 'nations.leader_name as claimer_leader_name'])
            ->whereIn($claims.'.target_nation_id', $targetIds)
            ->where($claims.'.status', RaidTargetClaim::STATUS_ACTIVE)
            ->where($claims.'.expires_at', '>', now())
            ->get()
            ->keyBy('target_nation_id');
    }
}

<?php

namespace App\Services\Raids;

use App\Models\RaidTargetClaim;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Single-active-claim workflow that lets members reserve a raid target.
 *
 * `pending_key` is 1 while a claim is active; the unique (target_nation_id, pending_key)
 * index guarantees one active claim per target even under concurrent requests.
 */
final class RaidTargetClaimService
{
    private const CLAIMED_MESSAGE = 'Another member already claimed this target.';

    /**
     * @throws ValidationException
     */
    public function claim(int $targetNationId, User $user): RaidTargetClaim
    {
        $this->expireStale($targetNationId);

        $active = RaidTargetClaim::query()
            ->where('target_nation_id', $targetNationId)
            ->where('status', RaidTargetClaim::STATUS_ACTIVE)
            ->first();

        if ($active !== null && (int) $active->nation_id === (int) $user->nation_id) {
            return $active;
        }

        if ($active !== null) {
            throw ValidationException::withMessages(['target_nation_id' => self::CLAIMED_MESSAGE]);
        }

        try {
            return RaidTargetClaim::query()->create([
                'target_nation_id' => $targetNationId,
                'nation_id' => (int) $user->nation_id,
                'user_id' => $user->id,
                'status' => RaidTargetClaim::STATUS_ACTIVE,
                'pending_key' => 1,
                'expires_at' => now()->addMinutes((int) config('raids.claims.ttl_minutes')),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['target_nation_id' => self::CLAIMED_MESSAGE]);
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function release(RaidTargetClaim $claim, User $user): void
    {
        if ((int) $claim->nation_id !== (int) $user->nation_id && ! Gate::forUser($user)->allows('view-raids')) {
            throw new AuthorizationException('Only the member who claimed this target can release it.');
        }

        if ($claim->status !== RaidTargetClaim::STATUS_ACTIVE) {
            return;
        }

        $claim->forceFill([
            'status' => RaidTargetClaim::STATUS_RELEASED,
            'pending_key' => null,
            'released_at' => now(),
        ])->save();
    }

    /**
     * Close the attacker's active claim once the war has been declared.
     */
    public function markDeclared(int $attackerNationId, int $targetNationId): void
    {
        RaidTargetClaim::query()
            ->where('target_nation_id', $targetNationId)
            ->where('nation_id', $attackerNationId)
            ->where('status', RaidTargetClaim::STATUS_ACTIVE)
            ->update([
                'status' => RaidTargetClaim::STATUS_DECLARED,
                'pending_key' => null,
                'released_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Expire active claims past their expiry, optionally for a single target.
     *
     * @return int claims expired
     */
    public function expireStale(?int $targetNationId = null): int
    {
        return RaidTargetClaim::query()
            ->where('status', RaidTargetClaim::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->when($targetNationId !== null, fn ($query) => $query->where('target_nation_id', $targetNationId))
            ->update([
                'status' => RaidTargetClaim::STATUS_EXPIRED,
                'pending_key' => null,
                'updated_at' => now(),
            ]);
    }

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

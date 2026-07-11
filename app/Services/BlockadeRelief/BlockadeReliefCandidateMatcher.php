<?php

namespace App\Services\BlockadeRelief;

use App\Models\BlockadeReliefRequest;
use App\Models\Nation;
use App\Models\War;
use App\Services\AllianceMembershipService;
use App\Services\War\NationMatchService;
use Illuminate\Support\Collection;

class BlockadeReliefCandidateMatcher
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly BlockadeReliefEligibilityService $eligibility,
        private readonly NationMatchService $nationMatchService,
    ) {}

    /**
     * @return Collection<int, array{nation:Nation, score:float, available_slots:int, ships:int, existing_war:bool}>
     */
    public function candidatesFor(BlockadeReliefRequest $request, int $limit = 25): Collection
    {
        $request->loadMissing('blockadingNation');
        $target = $request->blockadingNation;

        if (! $target) {
            return collect();
        }

        return Nation::query()
            ->whereIn('alliance_id', $this->membershipService->getAllianceIds())
            ->where('alliance_position', '!=', 'APPLICANT')
            ->where('vacation_mode_turns', 0)
            ->whereNotIn('id', [$request->requester_nation_id, $request->blockading_nation_id])
            ->whereHas('user', fn ($query) => $query
                ->where('disabled', false)
                ->whereNotNull('verified_at')
                ->whereHas('discordAccounts', fn ($accounts) => $accounts->whereNull('unlinked_at')))
            ->whereHas('military', fn ($query) => $query->where('ships', '>', 0))
            ->whereHas('accountProfile', fn ($query) => $query
                ->whereNotNull('last_active')
                ->where('last_active', '>=', now()->subHours($this->activityWindowHours())))
            ->with(['military', 'accountProfile', 'user.discordAccounts'])
            ->get()
            ->map(fn (Nation $candidate): ?array => $this->candidateProfile($request, $candidate))
            ->filter()
            ->sortBy([
                ['existing_war', 'desc'],
                ['score', 'desc'],
                ['ships', 'desc'],
            ])
            ->take($limit)
            ->values();
    }

    public function isEligibleFor(BlockadeReliefRequest $request, Nation $candidate): bool
    {
        return $this->candidateProfile($request, $candidate) !== null;
    }

    /** @return array{nation:Nation, score:float, available_slots:int, ships:int, existing_war:bool}|null */
    private function candidateProfile(BlockadeReliefRequest $request, Nation $candidate): ?array
    {
        $candidate->loadMissing(['military', 'accountProfile', 'user.discordAccounts']);
        $request->loadMissing('blockadingNation');
        $target = $request->blockadingNation;

        if (! $target || ! $this->eligibility->isEligibleUser($candidate->user)) {
            return null;
        }

        if ((int) $candidate->vacation_mode_turns > 0 || (int) ($candidate->military?->ships ?? 0) < 1) {
            return null;
        }

        $lastActive = $candidate->accountProfile?->last_active;
        if (! $lastActive || $lastActive->lt(now()->subHours($this->activityWindowHours()))) {
            return null;
        }

        $existingWar = War::query()
            ->active()
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair
                    ->where('att_id', $candidate->id)
                    ->where('def_id', $target->id))
                ->orWhere(fn ($pair) => $pair
                    ->where('att_id', $target->id)
                    ->where('def_id', $candidate->id)))
            ->exists();
        $availableSlots = max(0, $this->offensiveSlotCap($candidate) - (int) $candidate->offensive_wars_count);

        if (! $existingWar && ($availableSlots < 1 || ! $this->nationMatchService->canAttack($candidate, $target))) {
            return null;
        }

        $targetShips = max(1, (int) ($target->military?->ships ?? 1));
        $navyRatio = min(2.0, (int) $candidate->military->ships / $targetShips);
        $activityRatio = max(0.0, 1 - ($lastActive->diffInHours(now()) / $this->activityWindowHours()));

        return [
            'nation' => $candidate,
            'score' => round(($existingWar ? 40 : 0) + ($navyRatio * 25) + ($activityRatio * 20) + min(15, $availableSlots * 5), 2),
            'available_slots' => $availableSlots,
            'ships' => (int) $candidate->military->ships,
            'existing_war' => $existingWar,
        ];
    }

    private function offensiveSlotCap(Nation $nation): int
    {
        $cap = (int) config('war.slot_caps.default_offensive', 6);

        foreach (config('war.slot_caps.project_modifiers', []) as $project => $modifier) {
            if (($nation->projects[$project] ?? false) === true) {
                $cap += (int) $modifier;
            }
        }

        return $cap;
    }

    private function activityWindowHours(): int
    {
        return max(1, (int) config('war.plan_defaults.activity_window_hours', 72));
    }
}

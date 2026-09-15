<?php

namespace App\Services;

use App\Jobs\RefreshRaidIntelligence;
use App\Models\Nation;
use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use App\Models\War;
use App\Services\Economy\EconomyRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class RaidFinderService
{
    private ?array $permittedAllianceIds = null;

    public function __construct(
        protected AllianceMembershipService $membershipService,
        protected RaidPolicyService $raidPolicy,
        protected RaidIntelligenceService $intelligence,
        protected RaidSimulationService $simulation,
        protected RuntimeCapabilities $capabilities,
        protected RaidIntelligenceDemand $intelligenceDemand,
    ) {}

    /** @return Collection<int, mixed> */
    public function findTargets(int $nationId, ?callable $progress = null): Collection
    {
        $own = Nation::query()->findOrFail($nationId);
        abort_unless($this->membershipService->contains($own->alliance_id), 403, 'Nation does not belong to our alliance.');
        $now = CarbonImmutable::now();
        $attacker = $this->intelligence->nationAt($nationId, $now);
        $score = (float) ($attacker['score'] ?? $own->score);
        [$minimumScore, $maximumScore] = $this->declarationScoreRange($score);
        $allianceIds = $this->permittedAllianceIds = $this->raidPolicy->raidableAllianceIds();
        $observedScore = 'CAST('.(new RaidNationObservation)->getConnection()->getQueryGrammar()->wrap('payload->score').' AS DECIMAL(20, 4))';
        $observedCandidates = RaidNationObservation::query()->select('nation_id')
            ->where('observed_at', '<=', $now)
            ->whereNotExists(function ($newer) use ($now): void {
                $newer->selectRaw('1')->from('raid_nation_observations as newer')
                    ->whereColumn('newer.nation_id', 'raid_nation_observations.nation_id')
                    ->where('newer.observed_at', '<=', $now)
                    ->where(fn ($date) => $date->whereColumn('newer.observed_at', '>', 'raid_nation_observations.observed_at')
                        ->orWhere(fn ($sameDate) => $sameDate->whereColumn('newer.observed_at', 'raid_nation_observations.observed_at')
                            ->whereColumn('newer.id', '>', 'raid_nation_observations.id')));
            })
            ->whereRaw($observedScore.' BETWEEN ? AND ?', [$minimumScore, $maximumScore])
            ->where(fn ($q) => $q->whereNull('payload->alliance_id')->orWhere('payload->alliance_id', 0)->orWhereIn('payload->alliance_id', $allianceIds))
            ->where('payload->vacation_mode_turns', 0)->where('payload->beige_turns', 0)
            ->where('payload->color', '!=', 'beige');
        $candidates = Nation::query()->with('alliance')->where('id', '!=', $nationId)
            ->where(function ($query) use ($minimumScore, $maximumScore, $allianceIds, $observedCandidates): void {
                $query->where(fn ($candidate) => $candidate
                    ->whereBetween('score', [$minimumScore, $maximumScore])
                    ->where(fn ($q) => $q->whereNull('alliance_id')->orWhere('alliance_id', 0)->orWhereIn('alliance_id', $allianceIds))
                    ->where('vacation_mode_turns', 0)->where('beige_turns', 0)->where('color', '!=', 'beige'))
                    ->orWhereIn('id', $observedCandidates);
            })->get();
        $limit = max(1, (int) config('raids.candidate_limit', 100));
        $candidateIds = $candidates->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $cheapSnapshots = [];
        foreach (array_chunk($candidateIds, 50) as $ids) {
            foreach ($this->intelligence->nationsAt($ids, $now) as $id => $snapshot) {
                unset($snapshot['cities']);
                $cheapSnapshots[$id] = $snapshot;
            }
        }
        unset($snapshot);
        $cheapSnapshots[$nationId] = $attacker;
        $availability = $this->availabilityBatch($nationId, $candidateIds, $cheapSnapshots, checkLocalWars: true);
        $latestVictories = $this->latestVictories($candidateIds, $now);
        $selectedCandidates = $this->selectFreezeCandidates($candidates, $availability, $latestVictories, $limit);
        $ranked = collect();
        $refresh = [$nationId];

        foreach ($selectedCandidates as $candidate) {
            $targetId = (int) $candidate->id;
            $frozen = $this->intelligence->freeze($nationId, $targetId, $now);
            $target = $frozen['target'] ?? [];
            $targetSnapshot = $cheapSnapshots[$targetId] ?? [];
            $targetAvailability = $availability[$targetId] ?? $this->unavailableAvailability();
            if ($targetAvailability['eligible'] === false && ! ($targetAvailability['planning_only'] ?? false)) {
                continue;
            }
            $observedAt = $target['observed_at'] ?? $targetSnapshot['observed_at'] ?? null;
            $stale = empty($observedAt) || CarbonImmutable::parse($observedAt)->addSeconds((int) config('raids.fresh_seconds', 300))->isPast();
            if ($stale) {
                $refresh[] = $targetId;
            }
            $targetAllianceId = array_key_exists('alliance_id', $target) ? (int) $target['alliance_id']
                : (array_key_exists('alliance_id', $targetSnapshot) ? (int) $targetSnapshot['alliance_id'] : (int) $candidate->alliance_id);
            $targetAlliance = $targetAllianceId > 0 ? [
                'id' => $targetAllianceId,
                'name' => data_get($target, 'alliance.name') ?? data_get($targetSnapshot, 'alliance.name')
                    ?? ((int) $candidate->alliance_id === $targetAllianceId ? $candidate->alliance?->name : null)
                    ?? 'Alliance '.$targetAllianceId,
            ] : null;
            $prices = $frozen['prices']['liquidation'] ?? [];
            $lastBeige = $this->latestVictoryValue($latestVictories[$targetId] ?? null, $prices);
            $row = collect([
                'nation' => [
                    'id' => $targetId, 'leader_name' => $target['leader_name'] ?? $targetSnapshot['leader_name'] ?? $candidate->leader_name,
                    'alliance' => $targetAlliance,
                    'num_cities' => $target['num_cities'] ?? $targetSnapshot['num_cities'] ?? $candidate->num_cities,
                    'score' => $target['score'] ?? $targetSnapshot['score'] ?? $candidate->score,
                    'last_active' => $target['last_active'] ?? $targetSnapshot['last_active'] ?? null,
                    'soldiers' => $target['soldiers'] ?? $targetSnapshot['soldiers'] ?? null,
                    'tanks' => $target['tanks'] ?? $targetSnapshot['tanks'] ?? null,
                    'aircraft' => $target['aircraft'] ?? $targetSnapshot['aircraft'] ?? null,
                    'ships' => $target['ships'] ?? $targetSnapshot['ships'] ?? null,
                ],
                'value' => null, 'last_beige' => $lastBeige,
                'defensive_wars' => $targetAvailability['defensive_wars'], 'availability' => $targetAvailability,
                'intelligence' => ['observed_at' => $observedAt, 'stale' => $stale, 'status' => $frozen['status'] ?? 'incomplete'],
                'calculation' => $frozen['calculation'] ?? null,
                'prediction' => null,
            ]);
            if (($frozen['status'] ?? null) === 'ready') {
                $frozen['context']['iterations'] = max(16, (int) config('raids.finder_simulation_iterations', 32));
                $prediction = $this->simulation->evaluate($frozen['attacker'], $frozen['target'], $frozen['stockpile'], $frozen['prices'], $frozen['context']);
                $row->put('prediction', $prediction);
                $row->put('value', $prediction['expected_net'] ?? null);
            } else {
                $row->put('prediction', ['expected_net' => null, 'confidence' => 'unavailable', 'assumptions' => [$frozen['reason'] ?? 'Clean intelligence is incomplete.']]);
            }
            $ranked->push($row);
            unset($frozen, $target);
            if ($progress !== null && $ranked->count() % 5 === 0) {
                $progress($ranked->sortByDesc(fn (Collection $target) => $target->get('value') ?? -PHP_FLOAT_MAX)->values());
            }

        }
        $priorityIds = array_values(array_unique(array_merge([$nationId], $ranked->pluck('nation.id')->all(), $refresh)));
        $this->intelligenceDemand->prioritize($priorityIds);
        if ($this->capabilities->writesPublicWorld() && config('queue.default') !== 'sync') {
            foreach (array_chunk(array_slice($refresh, 0, $limit + 1), (int) config('raids.batch_size', 25)) as $ids) {
                RefreshRaidIntelligence::dispatch($ids);
            }
        }

        return $ranked->sortByDesc(fn (Collection $row) => $row->get('value') ?? -PHP_FLOAT_MAX)->values();
    }

    /**
     * Evaluate current eligibility for several targets using one nation read,
     * one active-war read, and one policy snapshot. The optional current map is
     * useful when the caller already loaded observations for the same response.
     *
     * @param  list<int>  $targetIds
     * @param  array<int, array<string, mixed>>|null  $current
     * @return array<int, array<string, mixed>>
     */
    public function availabilityBatch(
        int $nationId,
        array $targetIds,
        ?array $current = null,
        bool $checkLocalWars = false,
    ): array {
        $targetIds = array_values(array_unique(array_filter(
            array_map('intval', $targetIds),
            static fn (int $targetId): bool => $targetId > 0,
        )));
        if ($targetIds === []) {
            return [];
        }
        $ids = array_values(array_unique([$nationId, ...$targetIds]));
        $now = CarbonImmutable::now();
        $currentSnapshots = $current === null ? null : $this->normaliseSnapshots($current);
        $observed = $currentSnapshots ?? $this->normaliseSnapshots($this->intelligence->nationsAt($ids, $now));
        $missing = array_values(array_diff($ids, array_keys($observed)));
        if ($currentSnapshots !== null && $missing !== []) {
            foreach (array_chunk($missing, 50) as $missingIds) {
                $observed = $observed + $this->normaliseSnapshots($this->intelligence->nationsAt($missingIds, $now));
            }
        }
        $nations = Nation::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($currentSnapshots !== null && ! $checkLocalWars ? [] : $nations as $storedNation) {
            $id = (int) $storedNation->id;
            $observedAt = $observed[$id]['observed_at'] ?? null;
            if ($storedNation->updated_at !== null && ($observedAt === null || $storedNation->updated_at->gt(CarbonImmutable::parse($observedAt)))) {
                $observed[$id] = array_replace($observed[$id] ?? [], $storedNation->only([
                    'score', 'alliance_id', 'vacation_mode_turns', 'beige_turns', 'color',
                    'defensive_wars_count', 'offensive_wars_count', 'pirate_economy', 'advanced_pirate_economy',
                ]), ['observed_at' => $storedNation->updated_at->toIso8601String()]);
                unset($observed[$id]['active_wars']);
            }
        }
        $nation = $nations->get($nationId);
        abort_unless($nation && $this->membershipService->contains($nation->alliance_id), 403);
        $readLocalWars = $checkLocalWars || $currentSnapshots === null || $this->snapshotsNeedActiveWars($observed, $ids);
        $wars = collect();
        if ($readLocalWars) {
            $wars = War::query()->active()->where(fn ($q) => $q->whereIn('def_id', $ids)->orWhereIn('att_id', $ids))->get();
        }
        $this->permittedAllianceIds ??= $this->raidPolicy->raidableAllianceIds();
        $attacker = $observed[$nationId] ?? [];
        $results = [];
        foreach ($targetIds as $targetId) {
            $currentComplete = $currentSnapshots === null
                || (array_key_exists($nationId, $currentSnapshots) && array_key_exists($targetId, $currentSnapshots));
            $results[$targetId] = $this->evaluateAvailability(
                $nationId,
                $targetId,
                $attacker,
                $observed[$targetId] ?? [],
                $nation,
                $nations->get($targetId),
                $wars,
                $currentComplete,
            );
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function availability(int $nationId, int $targetId, ?array $current = null): array
    {
        return $this->availabilityBatch($nationId, [$targetId], $current)[$targetId] ?? $this->unavailableAvailability();
    }

    /**
     * @param  Collection<int, Nation>  $candidates
     * @param  array<int, array<string, mixed>>  $availability
     * @param  array<int, array{occurred_at: CarbonImmutable, payload: array<string, mixed>}>  $latestVictories
     * @return Collection<int, Nation>
     */
    private function selectFreezeCandidates(Collection $candidates, array $availability, array $latestVictories, int $limit): Collection
    {
        $eligible = $candidates->filter(fn (Nation $candidate): bool => ($availability[(int) $candidate->id]['eligible'] ?? null) !== false || ($availability[(int) $candidate->id]['planning_only'] ?? false))->values();
        if ($eligible->count() <= $limit) {
            return $eligible;
        }

        $withHistory = $eligible->filter(fn (Nation $candidate): bool => isset($latestVictories[(int) $candidate->id]))
            ->sortByDesc(fn (Nation $candidate): int => $latestVictories[(int) $candidate->id]['occurred_at']->getTimestamp())
            ->values();
        $withoutHistory = $eligible->reject(fn (Nation $candidate): bool => isset($latestVictories[(int) $candidate->id]))
            ->sortByDesc(fn (Nation $candidate): float => ((float) $candidate->score * 1000) + (float) ($candidate->num_cities ?? 0))
            ->values();
        $historyBudget = $withHistory->isNotEmpty() ? min($limit, max(1, (int) ceil($limit * 0.75))) : 0;
        $historySlots = min($withHistory->count(), $historyBudget);
        $unseenSlots = min($withoutHistory->count(), $limit - $historySlots);
        $selected = $withHistory->take($historySlots)->concat($withoutHistory->take($unseenSlots));
        $selectedIds = $selected->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $remaining = max(0, $limit - $selected->count());
        if ($remaining > 0) {
            $fill = $withHistory->concat($withoutHistory)
                ->reject(fn (Nation $candidate): bool => in_array((int) $candidate->id, $selectedIds, true))
                ->take($remaining);
            $selected = $selected->concat($fill);
        }

        return $selected->values();
    }

    /**
     * Read one latest successful victory per target for cheap preselection and
     * the legacy last-beige value. Only the bounded history window is scanned;
     * stockpile and profit evaluation remains limited by candidate_limit.
     *
     * @param  list<int>  $targetIds
     * @return array<int, array{occurred_at: CarbonImmutable, payload: array<string, mixed>}>
     */
    private function latestVictories(array $targetIds, CarbonImmutable $cutoff): array
    {
        if ($targetIds === []) {
            return [];
        }

        $rows = RaidAttackObservation::query()
            ->whereIn('def_id', $targetIds)
            ->where('occurred_at', '>=', $cutoff->subDays((int) config('raids.history_days', 30)))
            ->where('occurred_at', '<=', $cutoff)
            ->where('payload->type', 'VICTORY')
            ->latest('occurred_at')->latest('id')
            ->select(['id', 'att_id', 'def_id', 'occurred_at', 'payload'])->lazy(100);
        $latest = [];
        foreach ($rows as $row) {
            $targetId = (int) $row->def_id;
            if (isset($latest[$targetId])) {
                continue;
            }
            $payload = is_array($row->payload) ? $row->payload : [];
            $victor = (int) ($payload['victor'] ?? $payload['att_id'] ?? $row->att_id ?? 0);
            if ($victor < 1 || $victor === $targetId || strtoupper((string) ($payload['type'] ?? '')) !== 'VICTORY') {
                continue;
            }
            $occurredAt = $row->occurred_at instanceof CarbonImmutable
                ? $row->occurred_at
                : CarbonImmutable::parse($row->occurred_at);
            $latest[$targetId] = ['occurred_at' => $occurredAt, 'payload' => $payload];
        }

        return $latest;
    }

    /** @param array<string, mixed> $prices */
    private function latestVictoryValue(?array $victory, array $prices): ?float
    {
        if ($victory === null) {
            return null;
        }
        $payload = $victory['payload'] ?? [];
        if (! is_array($payload)) {
            return null;
        }
        $amounts = [];
        $hasAmount = false;
        foreach (['money_stolen', 'money_looted'] as $field) {
            if (! array_key_exists($field, $payload) || ! is_numeric($payload[$field]) || is_bool($payload[$field])) {
                continue;
            }
            $amounts['money'] = ($amounts['money'] ?? 0.0) + max(0.0, (float) $payload[$field]);
            $hasAmount = true;
        }
        foreach (EconomyRules::TRADE_RESOURCES as $resource) {
            $field = $resource.'_looted';
            if (! array_key_exists($field, $payload) || ! is_numeric($payload[$field]) || is_bool($payload[$field])) {
                continue;
            }
            $amounts[$resource] = max(0.0, (float) $payload[$field]);
            $hasAmount = true;
        }
        foreach ((array) ($payload['resource_looted'] ?? []) as $resource => $amount) {
            if (array_key_exists($resource, $amounts) || ! in_array($resource, EconomyRules::RESOURCE_KEYS, true) || ! is_numeric($amount) || is_bool($amount)) {
                continue;
            }
            $amounts[$resource] = max(0.0, (float) $amount);
            $hasAmount = true;
        }
        if (! $hasAmount) {
            return null;
        }
        $value = 0.0;
        foreach ($amounts as $resource => $amount) {
            if ($resource !== 'money' && ! array_key_exists($resource, $prices)) {
                return null;
            }
            $value += $amount * (float) ($prices[$resource] ?? 1.0);
        }

        return round($value, 2);
    }

    /** @param array<int, array<string, mixed>> $snapshots @return array<int, array<string, mixed>> */
    private function normaliseSnapshots(array $snapshots): array
    {
        $normalised = [];
        foreach ($snapshots as $nationId => $snapshot) {
            $nationId = (int) $nationId;
            if ($nationId > 0 && is_array($snapshot)) {
                $normalised[$nationId] = $snapshot;
            }
        }

        return $normalised;
    }

    /** @param array<int, array<string, mixed>> $current @param list<int> $ids */
    private function snapshotsNeedActiveWars(array $current, array $ids): bool
    {
        foreach ($ids as $id) {
            if (! array_key_exists($id, $current) || ! array_key_exists('active_wars', (array) $current[$id])) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: float, 1: float} */
    private function declarationScoreRange(float $score): array
    {
        $minimum = (float) config('milcom.game_rules.declaration_score_minimum_multiplier', 0.75);
        $maximum = (float) config('milcom.game_rules.declaration_score_maximum_multiplier', 2.50);

        return [$score * $minimum, $score * $maximum];
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     */
    private function evaluateAvailability(
        int $nationId,
        int $targetId,
        array $attacker,
        array $target,
        ?Nation $nation,
        ?Nation $targetNation,
        Collection $wars,
        bool $currentComplete,
    ): array {
        if ($targetNation === null || $nation === null) {
            return $this->unavailableAvailability();
        }
        $reasons = [];
        [$minimumScore, $maximumScore] = $this->declarationScoreRange((float) ($attacker['score'] ?? $nation->score));
        $targetScore = (float) ($target['score'] ?? $targetNation->score);
        if ($targetId === $nationId || $targetScore < $minimumScore || $targetScore > $maximumScore) {
            $reasons[] = 'Target is outside your declaration range.';
        }
        $allianceId = array_key_exists('alliance_id', $target) ? (int) $target['alliance_id'] : (int) $targetNation->alliance_id;
        if ($allianceId > 0 && ! in_array($allianceId, $this->permittedAllianceIds ?? [], true)) {
            $reasons[] = 'Alliance raid policy protects this target.';
        }
        if ((int) ($target['vacation_mode_turns'] ?? $targetNation->vacation_mode_turns) > 0 || (int) ($target['beige_turns'] ?? $targetNation->beige_turns) > 0 || strtolower((string) ($target['color'] ?? $targetNation->color)) === 'beige') {
            $reasons[] = 'Target is protected or in vacation mode.';
        }
        $targetWars = $wars->filter(fn (War $war): bool => (int) $war->def_id === $targetId || (int) $war->att_id === $targetId);
        $defensive = max((int) ($target['defensive_wars_count'] ?? $targetNation->defensive_wars_count), $targetWars->where('def_id', $targetId)->count(), collect($target['active_wars'] ?? [])->where('def_id', $targetId)->count());
        if ($defensive >= 3) {
            $reasons[] = 'All defensive slots are occupied.';
        }
        $alreadyFighting = $targetWars->contains(fn (War $war): bool => (int) $war->att_id === $nationId || (int) $war->def_id === $nationId)
            || collect($target['active_wars'] ?? [])->contains(fn ($war): bool => (int) ($war['att_id'] ?? 0) === $nationId || (int) ($war['def_id'] ?? 0) === $nationId);
        if ($alreadyFighting) {
            $reasons[] = 'You are already fighting this target.';
        }
        $offensiveCapacity = (int) config('milcom.game_rules.base_offensive_slots', 5);
        foreach ((array) config('milcom.game_rules.offensive_slot_projects', []) as $project => $modifier) {
            if ((bool) ($attacker[$project] ?? $nation->{$project})) {
                $offensiveCapacity += (int) $modifier;
            }
        }
        $offensiveCount = max((int) ($attacker['offensive_wars_count'] ?? 0), collect($attacker['active_wars'] ?? [])->where('att_id', $nationId)->count());
        if ($offensiveCount >= $offensiveCapacity) {
            $reasons[] = 'All your offensive slots are occupied.';
        }
        if ((int) ($attacker['vacation_mode_turns'] ?? $nation->vacation_mode_turns) > 0) {
            $reasons[] = 'You cannot declare while in vacation mode.';
        }
        $fresh = $currentComplete
            && isset($target['observed_at'], $attacker['observed_at'])
            && CarbonImmutable::parse($target['observed_at'])->addSeconds((int) config('raids.fresh_seconds', 300))->isFuture()
            && CarbonImmutable::parse($attacker['observed_at'])->addSeconds((int) config('raids.fresh_seconds', 300))->isFuture();
        if (! $fresh) {
            $reasons[] = 'Availability needs a fresh check.';
        }

        $planningOnly = $offensiveCount >= $offensiveCapacity && array_diff($reasons, [
            'All your offensive slots are occupied.', 'Availability needs a fresh check.',
        ]) === [];

        return ['planning_only' => $planningOnly, 'offensive_wars' => $offensiveCount, 'offensive_capacity' => $offensiveCapacity, 'eligible' => count($reasons) > ($fresh ? 0 : 1) ? false : ($fresh ? true : null), 'checked_at' => $target['observed_at'] ?? null, 'reasons' => $reasons, 'defensive_wars' => $defensive];
    }

    /** @return array{eligible: false, checked_at: string, reasons: list<string>, defensive_wars: int} */
    private function unavailableAvailability(): array
    {
        return ['eligible' => false, 'checked_at' => now()->toIso8601String(), 'reasons' => ['Target is unavailable.'], 'defensive_wars' => 0];
    }
}

<?php

namespace App\Services\BlockadeRelief;

use App\Enums\BlockadeReliefStatus;
use App\Models\BlockadeReliefRequest;
use App\Models\Nation;
use App\Models\User;
use App\Models\War;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BlockadeReliefService
{
    public function __construct(
        private readonly BlockadeReliefEligibilityService $eligibility,
        private readonly BlockadeReliefCandidateMatcher $candidateMatcher,
        private readonly BlockadeReliefNotificationService $notifications,
    ) {}

    public function create(User $user, int $warId, ?string $note = null, int $deadlineHours = 6): BlockadeReliefRequest
    {
        $requester = $this->eligibility->assertEligibleUser($user);
        $war = $this->activeWarForBlockadedNation($requester, $warId);

        if (BlockadeReliefRequest::query()
            ->where('requester_nation_id', $requester->id)
            ->where('war_id', $war->id)
            ->whereNotNull('pending_key')
            ->exists()) {
            throw ValidationException::withMessages([
                'pending' => 'You already have an active blockade relief request for this war.',
            ]);
        }

        try {
            $request = BlockadeReliefRequest::query()->create([
                'requester_nation_id' => $requester->id,
                'war_id' => $war->id,
                'blockading_nation_id' => (int) $war->naval_blockade,
                'status' => BlockadeReliefStatus::Pending,
                'pending_key' => 1,
                'note' => filled($note) ? trim((string) $note) : null,
                'deadline_at' => now()->addHours(max(1, min(24, $deadlineHours))),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'pending' => 'You already have an active blockade relief request for this war.',
            ]);
        }

        $request->load(['requester.user.discordAccounts', 'blockadingNation.military']);
        $candidates = $this->candidateMatcher->candidatesFor($request, 5)->pluck('nation');
        $this->notifications->enqueue($request, 'created', $candidates);

        return $request->fresh(['requester', 'blockadingNation', 'claimer']);
    }

    public function claim(BlockadeReliefRequest $request, User $user): BlockadeReliefRequest
    {
        $candidate = $this->eligibility->assertEligibleUser($user);

        /** @var array{request:BlockadeReliefRequest,error:string|null,event:string|null} $result */
        $result = DB::transaction(function () use ($request, $candidate): array {
            $locked = BlockadeReliefRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BlockadeReliefStatus::Pending) {
                return ['request' => $locked, 'error' => 'This relief request is no longer available to claim.', 'event' => null];
            }

            $terminalReason = $this->terminalReason($locked);
            if ($terminalReason !== null) {
                $this->transitionToTerminal($locked, $terminalReason);

                return ['request' => $locked, 'error' => 'The blockade is no longer active.', 'event' => $locked->status->value];
            }

            if (! $this->candidateMatcher->isEligibleFor($locked, $candidate)) {
                return ['request' => $locked, 'error' => 'You are not currently eligible to take this blockade relief request.', 'event' => null];
            }

            $locked->forceFill([
                'status' => BlockadeReliefStatus::Claimed,
                'claimed_by_nation_id' => $candidate->id,
                'claimed_at' => now(),
            ])->save();

            return ['request' => $locked->fresh(), 'error' => null, 'event' => 'claimed'];
        }, attempts: 3);

        if ($result['event'] !== null) {
            $this->notifications->enqueue($result['request'], $result['event'], collect([$candidate]));
        }

        if ($result['error'] !== null) {
            throw ValidationException::withMessages(['claim' => $result['error']]);
        }

        return $result['request']->load(['requester', 'blockadingNation', 'claimer']);
    }

    public function cancel(BlockadeReliefRequest $request, User $user): BlockadeReliefRequest
    {
        $requester = $this->eligibility->assertEligibleUser($user);

        $cancelled = DB::transaction(function () use ($request, $requester): BlockadeReliefRequest {
            $locked = BlockadeReliefRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->requester_nation_id !== (int) $requester->id) {
                throw ValidationException::withMessages(['request' => 'Only the requester may cancel this relief request.']);
            }

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['request' => 'Only an active relief request may be cancelled.']);
            }

            $locked->forceFill([
                'status' => BlockadeReliefStatus::Cancelled,
                'pending_key' => null,
                'cancelled_at' => now(),
                'resolution_reason' => 'requester_cancelled',
            ])->save();

            return $locked->fresh();
        }, attempts: 3);

        $this->notifications->enqueue($cancelled, 'cancelled', collect());

        return $cancelled->load(['requester', 'blockadingNation', 'claimer']);
    }

    public function reconcile(BlockadeReliefRequest $request): BlockadeReliefRequest
    {
        /** @var array{request:BlockadeReliefRequest,event:string|null,former_claimer:Nation|null} $result */
        $result = DB::transaction(function () use ($request): array {
            $locked = BlockadeReliefRequest::query()
                ->with(['requester.user.discordAccounts', 'claimer.user.discordAccounts', 'blockadingNation'])
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isActive()) {
                return ['request' => $locked, 'event' => null, 'former_claimer' => null];
            }

            $reason = $this->terminalReason($locked);
            if ($reason !== null) {
                $formerClaimer = $locked->claimer;
                $this->transitionToTerminal($locked, $reason);

                return ['request' => $locked->fresh(), 'event' => $locked->status->value, 'former_claimer' => $formerClaimer];
            }

            if ($locked->status === BlockadeReliefStatus::Claimed
                && (! $locked->claimer || ! $this->candidateMatcher->isEligibleFor($locked, $locked->claimer))) {
                $formerClaimer = $locked->claimer;
                $locked->forceFill([
                    'status' => BlockadeReliefStatus::Pending,
                    'claimed_by_nation_id' => null,
                    'claimed_at' => null,
                    'resolution_reason' => null,
                ])->save();

                return ['request' => $locked->fresh(), 'event' => 'reopened', 'former_claimer' => $formerClaimer];
            }

            return ['request' => $locked, 'event' => null, 'former_claimer' => null];
        }, attempts: 3);

        if ($result['event'] !== null) {
            $recipients = collect([$result['former_claimer']])->filter();
            if ($result['event'] === 'reopened') {
                $recipients = $recipients->merge(
                    $this->candidateMatcher->candidatesFor($result['request'], 5)->pluck('nation')
                );
            }
            $this->notifications->enqueue($result['request'], $result['event'], $recipients);
        }

        return $result['request'];
    }

    /** @return Collection<int, BlockadeReliefRequest> */
    public function requestsFor(User $user): Collection
    {
        $nation = $this->eligibility->assertEligibleUser($user);

        return BlockadeReliefRequest::query()
            ->where('requester_nation_id', $nation->id)
            ->with(['requester', 'blockadingNation', 'claimer'])
            ->latest()
            ->limit(50)
            ->get();
    }

    /** @return Collection<int, BlockadeReliefRequest> */
    public function availableFor(User $user): Collection
    {
        $candidate = $this->eligibility->assertEligibleUser($user);

        return BlockadeReliefRequest::query()
            ->where('status', BlockadeReliefStatus::Pending->value)
            ->where('deadline_at', '>', now())
            ->with(['requester', 'blockadingNation.military', 'claimer'])
            ->orderBy('deadline_at')
            ->limit(50)
            ->get()
            ->filter(fn (BlockadeReliefRequest $request): bool => $this->candidateMatcher->isEligibleFor($request, $candidate))
            ->values();
    }

    /** @return Collection<int, War> */
    public function blockadedWarsFor(User $user): Collection
    {
        $nation = $this->eligibility->assertEligibleUser($user);

        return War::query()
            ->active()
            ->where(fn ($query) => $query->where('att_id', $nation->id)->orWhere('def_id', $nation->id))
            ->whereNotNull('naval_blockade')
            ->where('naval_blockade', '!=', $nation->id)
            ->with(['attacker', 'defender'])
            ->get()
            ->filter(fn (War $war): bool => in_array((int) $war->naval_blockade, [(int) $war->att_id, (int) $war->def_id], true))
            ->values();
    }

    private function activeWarForBlockadedNation(Nation $nation, int $warId): War
    {
        $war = War::query()
            ->active()
            ->whereKey($warId)
            ->where(fn ($query) => $query->where('att_id', $nation->id)->orWhere('def_id', $nation->id))
            ->first();

        $opponentId = $war
            ? ((int) $war->att_id === (int) $nation->id ? (int) $war->def_id : (int) $war->att_id)
            : null;

        if (! $war || (int) $war->naval_blockade !== $opponentId) {
            throw ValidationException::withMessages([
                'war_id' => 'The selected active war does not currently have your nation blockaded.',
            ]);
        }

        return $war;
    }

    private function terminalReason(BlockadeReliefRequest $request): ?string
    {
        if ($request->deadline_at->isPast()) {
            return 'deadline_expired';
        }

        if (! $this->eligibility->isEligibleUser($request->requester?->user)) {
            return 'requester_ineligible';
        }

        $war = War::query()->whereKey($request->war_id)->first();
        if (! $war || $war->end_date !== null || (int) $war->turns_left < 1) {
            return 'war_ended';
        }

        if ((int) $war->naval_blockade !== (int) $request->blockading_nation_id) {
            return 'blockade_ended';
        }

        return null;
    }

    private function transitionToTerminal(BlockadeReliefRequest $request, string $reason): void
    {
        $status = match ($reason) {
            'deadline_expired' => BlockadeReliefStatus::Expired,
            'requester_ineligible' => BlockadeReliefStatus::Cancelled,
            default => BlockadeReliefStatus::Resolved,
        };
        $timestampField = match ($status) {
            BlockadeReliefStatus::Expired => 'expired_at',
            BlockadeReliefStatus::Cancelled => 'cancelled_at',
            default => 'resolved_at',
        };

        $request->forceFill([
            'status' => $status,
            'pending_key' => null,
            $timestampField => now(),
            'resolution_reason' => $reason,
        ])->save();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}

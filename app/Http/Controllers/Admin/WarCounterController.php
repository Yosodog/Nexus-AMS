<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\WarAttack;
use App\Models\WarCounter;
use App\Models\WarCounterAssignment;
use App\Services\AllianceMembershipService;
use App\Services\War\CounterAssignmentService;
use App\Services\War\NotificationService;
use App\Services\War\PlanOrchestratorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin controller for counter planning rooms.
 */
class WarCounterController extends Controller
{
    /**
     * Show counter planning room.
     *
     * @throws AuthorizationException
     */
    public function show(
        Request $request,
        WarCounter $counter,
        AllianceMembershipService $membershipService,
        CounterAssignmentService $assignmentService
    ): View {
        $this->authorize('view-wars');

        $counter->load([
            'aggressor.alliance',
            'aggressor.military',
            'aggressor.accountProfile',
            'assignments.friendlyNation.alliance',
            'assignments.friendlyNation.military',
            'assignments.friendlyNation.accountProfile',
        ]);

        // Build candidate list from friendly alliances
        $friendlyAllianceIds = $membershipService->getAllianceIds();
        $friendlies = Nation::query()
            ->whereIn('alliance_id', $friendlyAllianceIds)
            ->where(function ($query) {
                $query->whereNull('alliance_position')
                    ->orWhere('alliance_position', '!=', 'APPLICANT');
            })
            ->where(function ($query) {
                $query->whereNull('vacation_mode_turns')
                    ->orWhere('vacation_mode_turns', '<=', 0);
            })
            ->with(['military', 'latestSignIn', 'alliance'])
            ->with('accountProfile')
            ->get();

        $candidates = $assignmentService->listCandidates($counter, $friendlies);

        // Exclude nations already present in assignments to avoid duplicate rows in candidates
        $existingIds = $counter->assignments()->pluck('friendly_nation_id')->all();
        $candidates = $candidates->reject(fn ($row) => in_array($row['friendly']->id, $existingIds, true))->values();

        // Recent wars between aggressor and our alliances (last 30 days)
        $recentWarsAgainstUs = \App\Models\War::query()
            ->with(['attacker.alliance', 'attacker.accountProfile', 'defender.alliance', 'defender.accountProfile'])
            ->where('date', '>=', now()->subDays(30))
            ->where(function ($q) use ($counter, $friendlyAllianceIds) {
                $q->where(function ($q2) use ($counter, $friendlyAllianceIds) {
                    $q2->where('att_id', $counter->aggressor_nation_id)
                        ->whereHas('defender', function ($q3) use ($friendlyAllianceIds) {
                            $q3->whereIn('alliance_id', $friendlyAllianceIds->all());
                        });
                })->orWhere(function ($q2) use ($counter, $friendlyAllianceIds) {
                    $q2->where('def_id', $counter->aggressor_nation_id)
                        ->whereHas('attacker', function ($q3) use ($friendlyAllianceIds) {
                            $q3->whereIn('alliance_id', $friendlyAllianceIds->all());
                        });
                });
            })
            ->latest('date')
            ->limit(25)
            ->get();

        $enemyWarAttacks = WarAttack::query()
            ->with(['attacker.alliance', 'attacker.accountProfile', 'defender.alliance', 'defender.accountProfile'])
            ->where(function ($query) use ($counter) {
                $query->where('att_id', $counter->aggressor_nation_id)
                    ->orWhere('def_id', $counter->aggressor_nation_id);
            })
            ->latest('date')
            ->limit(50)
            ->get();

        return view('admin.war-room.counter', [
            'counter' => $counter,
            'assignments' => $counter->assignments()->with(['friendlyNation.alliance', 'friendlyNation.military', 'friendlyNation.accountProfile'])->orderByDesc('match_score')->get(),
            'enemyWarAttacks' => $enemyWarAttacks,
            'candidates' => $candidates,
            'recentWarsAgainstUs' => $recentWarsAgainstUs,
            'defaultWarReason' => $this->defaultWarReason($membershipService),
        ]);
    }

    /**
     * Manually create a draft counter.
     *
     * @throws AuthorizationException
     */
    public function store(
        Request $request,
        PlanOrchestratorService $orchestrator,
        AllianceMembershipService $membershipService
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $data = $request->validate([
            'aggressor_nation_id' => ['required', 'integer', 'exists:nations,id'],
            'team_size' => ['nullable', 'integer', 'min:1', 'max:10'],
            'war_declaration_type' => ['nullable', Rule::in($this->allowedWarTypes())],
            'discord_forum_channel_id' => ['nullable', 'string', 'max:190'],
            'war_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $existing = WarCounter::query()
            ->where('aggressor_nation_id', $data['aggressor_nation_id'])
            ->whereIn('status', ['draft', 'active'])
            ->first();

        if ($existing) {
            return Redirect::route('admin.war-counters.show', $existing)
                ->with('alert-type', 'info')
                ->with('alert-message', 'Counter already exists for this aggressor.');
        }

        $aggressor = Nation::query()->findOrFail($data['aggressor_nation_id']);

        $suppressingAlliances = $orchestrator->getActiveEnemyAllianceIds();
        if (in_array($aggressor->alliance_id, $suppressingAlliances, true)) {
            return Redirect::back()
                ->with('alert-type', 'warning')
                ->with('alert-message', 'Counter suppressed by an active war plan.');
        }

        $counter = WarCounter::query()->create([
            'aggressor_nation_id' => $aggressor->id,
            'team_size' => $data['team_size'] ?? config('war.counters.default_team_size', 3),
            'war_declaration_type' => $this->sanitizeWarType($data['war_declaration_type'] ?? null),
            'discord_forum_channel_id' => $data['discord_forum_channel_id'] ?? null,
            'war_reason' => $this->sanitizeWarReason($data['war_reason'] ?? $this->defaultWarReason($membershipService)),
            'status' => 'draft',
        ]);

        return Redirect::route('admin.war-counters.show', $counter)
            ->with('alert-type', 'success')
            ->with('alert-message', 'Counter created.');
    }

    /**
     * Auto-pick assignments for the counter.
     *
     * @throws AuthorizationException
     */
    public function autoPick(
        WarCounter $counter,
        CounterAssignmentService $assignmentService,
        AllianceMembershipService $membershipService
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $friendlies = Nation::query()
            ->whereIn('alliance_id', $membershipService->getAllianceIds())
            ->where(function ($query) {
                $query->whereNull('alliance_position')
                    ->orWhere('alliance_position', '!=', 'APPLICANT');
            })
            ->where(function ($query) {
                $query->whereNull('vacation_mode_turns')
                    ->orWhere('vacation_mode_turns', '<=', 0);
            })
            ->get();

        $assignmentService->proposeAssignments($counter, $friendlies);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Counter assignments refreshed.');
    }

    /**
     * Manually add/lock a friendly nation to this counter.
     *
     * @throws AuthorizationException
     */
    public function storeManualAssignment(
        Request $request,
        WarCounter $counter,
        CounterAssignmentService $assignmentService,
        AllianceMembershipService $membershipService
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $data = $request->validate([
            'friendly_nation_id' => ['required', 'integer', 'exists:nations,id'],
            'match_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $allowedAlliances = $membershipService->getAllianceIds();
        $friendly = Nation::query()->findOrFail($data['friendly_nation_id']);

        if ($friendly->alliance_id && ! in_array($friendly->alliance_id, $allowedAlliances->all(), true)) {
            return Redirect::back()
                ->with('alert-type', 'warning')
                ->with('alert-message', 'Nation is not within friendly alliances.');
        }

        if ((int) ($friendly->vacation_mode_turns ?? 0) > 0) {
            return Redirect::back()
                ->with('alert-type', 'warning')
                ->with('alert-message', 'Vacation mode nations cannot be assigned.');
        }

        $assignmentService->addManualAssignment($counter, $friendly, $data['match_score'] ?? null);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Manual assignment saved and locked.');
    }

    /**
     * Mark a proposed assignment as assigned.
     *
     * @throws AuthorizationException
     */
    public function assign(WarCounter $counter, WarCounterAssignment $assignment, CounterAssignmentService $assignmentService): RedirectResponse
    {
        $this->authorize('manage-war-room');

        if ($assignment->war_counter_id !== $counter->id) {
            abort(404);
        }

        $assignment->update([
            'status' => 'assigned',
            'is_locked' => true,
        ]);

        // Keep proposed rows within team size
        $assignmentService->enforceProposedLimit($counter);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Assignment marked as assigned.');
    }

    /**
     * Remove a proposed assignment from the counter.
     */
    public function removeAssignment(WarCounter $counter, WarCounterAssignment $assignment): RedirectResponse
    {
        $this->authorize('manage-war-room');

        if ($assignment->war_counter_id !== $counter->id) {
            abort(404);
        }

        if ($assignment->status !== 'proposed') {
            return Redirect::back()
                ->with('alert-type', 'warning')
                ->with('alert-message', 'Only proposed assignments can be removed.');
        }

        $assignment->delete();

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Proposed assignment removed.');
    }

    /**
     * Revert an assigned row back to proposed so it can be edited/removed.
     */
    public function unassign(WarCounter $counter, WarCounterAssignment $assignment, CounterAssignmentService $assignmentService): RedirectResponse
    {
        $this->authorize('manage-war-room');

        if ($assignment->war_counter_id !== $counter->id) {
            abort(404);
        }

        if ($assignment->status !== 'assigned') {
            return Redirect::back()
                ->with('alert-type', 'warning')
                ->with('alert-message', 'Only assigned rows can be unassigned.');
        }

        $assignment->update([
            'status' => 'proposed',
            'is_locked' => false,
        ]);

        // Ensure we still respect proposed team size limit
        $assignmentService->enforceProposedLimit($counter);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Assignment reverted to proposed.');
    }

    /**
     * Finalize counter assignments and notify.
     */
    public function finalize(
        Request $request,
        WarCounter $counter,
        CounterAssignmentService $assignmentService,
        NotificationService $notificationService,
        AllianceMembershipService $membershipService
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $settings = $request->validate([
            'war_declaration_type' => ['nullable', Rule::in($this->allowedWarTypes())],
            'war_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('war_declaration_type', $settings) || array_key_exists('war_reason', $settings)) {
            $updatePayload = [];

            if (array_key_exists('war_declaration_type', $settings)) {
                $updatePayload['war_declaration_type'] = $this->sanitizeWarType($settings['war_declaration_type']);
            }

            if (array_key_exists('war_reason', $settings)) {
                $updatePayload['war_reason'] = $this->sanitizeWarReason($settings['war_reason'] ?? null)
                    ?? $this->defaultWarReason($membershipService);
            }

            $counter->update($updatePayload);
        }

        if (! $counter->war_reason) {
            $counter->update([
                'war_reason' => $this->defaultWarReason($membershipService),
            ]);
        }

        $channels = [
            'in_game' => $request->boolean('notify_in_game'),
            'create_room' => $request->boolean('notify_discord_room'),
        ];

        $counter = $assignmentService->finalize($counter);

        $assignments = $counter->assignments()->with('friendlyNation')->get();

        $result = $notificationService->queueCounterFinalizedNotifications($counter, $assignments, $channels);

        $message = 'Counter finalized.';

        if ($result['rooms_queued'] > 0) {
            $message .= " {$result['rooms_queued']} Discord war room job(s) queued.";
        }

        if ($result['in_game_skipped'] > 0) {
            $message .= ' In-game mail selected but not implemented yet.';
        }

        if ($result['skipped_no_forum']) {
            $message .= ' No default/override forum ID is configured, so Discord war rooms were not queued.';
        }

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', $message);
    }

    /**
     * Archive a counter.
     */
    public function archive(WarCounter $counter, CounterAssignmentService $assignmentService): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $assignmentService->archive($counter);

        return Redirect::route('admin.war-room')
            ->with('alert-type', 'success')
            ->with('alert-message', 'Counter archived.');
    }

    public function update(Request $request, WarCounter $counter): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $data = $request->validate([
            'war_declaration_type' => ['required', Rule::in($this->allowedWarTypes())],
            'team_size' => ['nullable', 'integer', 'min:1', 'max:10'],
            'discord_forum_channel_id' => ['nullable', 'string', 'max:190'],
            'war_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $counter->update([
            'war_declaration_type' => $this->sanitizeWarType($data['war_declaration_type']),
            'team_size' => array_key_exists('team_size', $data) && $data['team_size'] !== null
                ? (int) $data['team_size']
                : $counter->team_size,
            'discord_forum_channel_id' => $data['discord_forum_channel_id'] ?? null,
            'war_reason' => $this->sanitizeWarReason($data['war_reason'] ?? null),
        ]);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Counter settings updated.');
    }

    /**
     * @return array<int, string>
     */
    protected function allowedWarTypes(): array
    {
        return array_keys(config('war.war_types', []));
    }

    /**
     * Sanitize the provided war type.
     */
    protected function sanitizeWarType(?string $warType): string
    {
        $warType = $warType ? strtolower($warType) : null;

        return in_array($warType, $this->allowedWarTypes(), true)
            ? $warType
            : config('war.plan_defaults.plan_type', 'ordinary');
    }

    protected function sanitizeWarReason(?string $warReason): ?string
    {
        $warReason = trim((string) $warReason);

        return $warReason !== '' ? $warReason : null;
    }

    protected function defaultWarReason(AllianceMembershipService $membershipService): string
    {
        $primaryAllianceId = $membershipService->getPrimaryAllianceId();
        $allianceName = Alliance::query()->whereKey($primaryAllianceId)->value('name');

        return sprintf('%s Counter', $allianceName ?: config('app.name', 'Alliance'));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Nation;
use App\Models\WarCounter;
use App\Services\AllianceMembershipService;
use App\Services\War\CounterAssignmentService;
use App\Services\War\LiveFeedQueryService;
use App\Services\War\NotificationService;
use App\Services\War\PlanOrchestratorService;
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
     */
    public function show(
        Request $request,
        WarCounter $counter,
        LiveFeedQueryService $liveFeed
    ): View {
        $this->authorize('view-wars');

        $counter->load(['aggressor.alliance', 'aggressor.military', 'assignments.friendlyNation.alliance', 'assignments.friendlyNation.military']);

        $feedFilters = $request->only(['minutes', 'hours', 'attack_types']);

        return view('admin.war-room.counter', [
            'counter' => $counter,
            'assignments' => $counter->assignments()->with(['friendlyNation.alliance', 'friendlyNation.military'])->orderByDesc('match_score')->get(),
            'liveFeed' => $liveFeed->forCounter($counter, $feedFilters),
            'recentAggressorAttacks' => $liveFeed->recentForNation($counter->aggressor_nation_id),
            'feedFilters' => $feedFilters,
        ]);
    }

    /**
     * Manually create a draft counter.
     */
    public function store(
        Request $request,
        PlanOrchestratorService $orchestrator
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $data = $request->validate([
            'aggressor_nation_id' => ['required', 'integer', 'exists:nations,id'],
            'team_size' => ['nullable', 'integer', 'min:1', 'max:10'],
            'war_declaration_type' => ['nullable', Rule::in($this->allowedWarTypes())],
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
            'status' => 'draft',
        ]);

        return Redirect::route('admin.war-counters.show', $counter)
            ->with('alert-type', 'success')
            ->with('alert-message', 'Counter created.');
    }

    /**
     * Auto-pick assignments for the counter.
     */
    public function autoPick(
        WarCounter $counter,
        CounterAssignmentService $assignmentService,
        AllianceMembershipService $membershipService
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $friendlies = Nation::query()
            ->whereIn('alliance_id', $membershipService->getAllianceIds())
            ->get();

        $assignmentService->proposeAssignments($counter, $friendlies);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Counter assignments refreshed.');
    }

    /**
     * Finalize counter assignments and notify.
     */
    public function finalize(
        Request $request,
        WarCounter $counter,
        CounterAssignmentService $assignmentService,
        NotificationService $notificationService
    ): RedirectResponse {
        $this->authorize('manage-war-room');

        $settings = $request->validate([
            'war_declaration_type' => ['nullable', Rule::in($this->allowedWarTypes())],
        ]);

        if (array_key_exists('war_declaration_type', $settings)) {
            $counter->update([
                'war_declaration_type' => $this->sanitizeWarType($settings['war_declaration_type']),
            ]);
        }

        $channels = [
            'in_game' => $request->boolean('notify_in_game'),
            'discord' => $request->boolean('notify_discord'),
            'create_room' => $request->boolean('notify_discord_room'),
        ];

        $counter = $assignmentService->finalize($counter);

        $assignments = $counter->assignments()->with('friendlyNation')->get();

        $notificationService->queueCounterFinalizedNotifications($counter, $assignments, $channels);

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Counter finalized and notifications queued.');
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
        ]);

        $counter->update([
            'war_declaration_type' => $this->sanitizeWarType($data['war_declaration_type']),
            'team_size' => array_key_exists('team_size', $data) && $data['team_size'] !== null
                ? (int) $data['team_size']
                : $counter->team_size,
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

    protected function sanitizeWarType(?string $warType): string
    {
        $warType = $warType ? strtolower($warType) : null;

        return in_array($warType, $this->allowedWarTypes(), true)
            ? $warType
            : config('war.plan_defaults.plan_type', 'ordinary');
    }
}

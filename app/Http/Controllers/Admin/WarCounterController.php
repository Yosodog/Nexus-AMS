<?php

namespace App\Http\Controllers\Admin;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceExpenseOccurred;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WarCounterReimbursementRequest;
use App\Models\Account;
use App\Models\Alliance;
use App\Models\AllianceFinanceEntry;
use App\Models\Nation;
use App\Models\WarAttack;
use App\Models\WarCounter;
use App\Models\WarCounterAssignment;
use App\Models\WarCounterReimbursement;
use App\Services\AccountService;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\War\CounterAssignmentService;
use App\Services\War\CounterReimbursementService;
use App\Services\War\NotificationService;
use App\Services\War\PlanOrchestratorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
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
        CounterAssignmentService $assignmentService,
        CounterReimbursementService $counterReimbursementService
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

        $counterCosting = $counterReimbursementService->buildCostingSnapshot($counter, $friendlyAllianceIds);

        return view('admin.war-room.counter', [
            'counter' => $counter,
            'assignments' => $counter->assignments()->with(['friendlyNation.alliance', 'friendlyNation.military', 'friendlyNation.accountProfile'])->orderByDesc('match_score')->get(),
            'enemyWarAttacks' => $enemyWarAttacks,
            'candidates' => $candidates,
            'recentWarsAgainstUs' => $recentWarsAgainstUs,
            'defaultWarReason' => $this->defaultWarReason($membershipService),
            'counterCosting' => $counterCosting,
            'canManageAccounts' => Gate::allows('manage-accounts'),
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

        $assignedIds = $counter->assignments()
            ->where('status', 'assigned')
            ->pluck('id');

        $counter = $assignmentService->finalize($counter);

        $assignments = $counter->assignments()
            ->whereIn('id', $assignedIds)
            ->where('status', 'finalized')
            ->with('friendlyNation')
            ->get();

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
     * Store a member reimbursement for an active counter.
     *
     * @throws AuthorizationException
     */
    public function storeReimbursement(
        WarCounterReimbursementRequest $request,
        WarCounter $counter,
        AllianceMembershipService $membershipService,
        CounterReimbursementService $counterReimbursementService,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $this->authorize('manage-war-room');
        $this->authorize('manage-accounts');

        if ($counter->status !== 'active') {
            return Redirect::back()
                ->with('alert-type', 'warning')
                ->with('alert-message', 'Reimbursements are only available while a counter is active.')
                ->withInput();
        }

        $costing = $counterReimbursementService->buildCostingSnapshot($counter, $membershipService->getAllianceIds());

        $nationId = (int) $request->integer('nation_id');
        $participant = $costing['participants_by_nation']->get($nationId);

        if (! $participant) {
            return Redirect::back()
                ->with('alert-type', 'warning')
                ->with('alert-message', 'Selected member is not currently involved in this counter.')
                ->withInput();
        }

        $account = Account::query()->find($request->integer('account_id'));

        if (! $account || (int) $account->nation_id !== $nationId) {
            return Redirect::back()
                ->with('alert-type', 'warning')
                ->with('alert-message', 'The selected account does not belong to that member nation.')
                ->withInput();
        }

        $resourceAdjustments = [
            'gasoline' => round((float) $request->input('gasoline', 0), 2),
            'munitions' => round((float) $request->input('munitions', 0), 2),
            'steel' => round((float) $request->input('steel', 0), 2),
            'aluminum' => round((float) $request->input('aluminum', 0), 2),
        ];
        $resourcesCost = round(
            ($resourceAdjustments['gasoline'] * (float) ($costing['prices']['gasoline'] ?? 0))
            + ($resourceAdjustments['munitions'] * (float) ($costing['prices']['munitions'] ?? 0))
            + ($resourceAdjustments['steel'] * (float) ($costing['prices']['steel'] ?? 0))
            + ($resourceAdjustments['aluminum'] * (float) ($costing['prices']['aluminum'] ?? 0)),
            2
        );
        $unitLossCost = round((float) $request->input('unit_loss_cost', 0), 2);
        $infraLossCost = round((float) $request->input('infra_loss_cost', 0), 2);
        $totalCost = round($unitLossCost + $infraLossCost, 2);
        $hasResourcePayout = collect($resourceAdjustments)->some(fn (float $amount) => $amount > 0);

        if ($totalCost <= 0 && ! $hasResourcePayout) {
            return Redirect::back()
                ->with('alert-type', 'warning')
                ->with('alert-message', 'Enter a money reimbursement amount or at least one resource amount.')
                ->withInput();
        }

        $note = $this->sanitizeWarReason($request->input('note'));
        $correlationId = (string) Str::uuid();
        $transactionNote = sprintf('Counter reimbursement #%d for Nation #%d', $counter->id, $nationId);

        if ($note) {
            $transactionNote .= ' ('.$note.')';
        }

        $transactionNote = Str::limit($transactionNote, 255, '');

        $meta = [
            'counter_cost_snapshot' => [
                'resource_usage' => $participant['resource_usage'] ?? [],
                'reimbursed_resources' => $participant['reimbursed_resources'] ?? [],
                'outstanding_resources' => $participant['outstanding_resources'] ?? [],
                'total_resources_cost' => (float) ($participant['resources_cost'] ?? 0.0),
                'total_unit_loss_cost' => (float) ($participant['unit_loss_cost'] ?? 0.0),
                'total_infra_loss_cost' => (float) ($participant['infra_loss_cost'] ?? 0.0),
                'total_cost' => (float) ($participant['total_cost'] ?? 0.0),
                'default_unit_loss_cost' => (float) ($participant['outstanding_unit_loss_cost'] ?? 0.0),
                'default_infra_loss_cost' => (float) ($participant['outstanding_infra_loss_cost'] ?? 0.0),
                'default_total_cost' => (float) ($participant['outstanding_cost'] ?? 0.0),
                'wars_count' => (int) ($participant['war_count'] ?? 0),
                'active_wars_count' => (int) ($participant['active_war_count'] ?? 0),
                'pricing' => $costing['prices'] ?? [],
                'unit_pricing' => $costing['unit_prices'] ?? [],
                'trade_price_as_of' => $costing['trade_price_as_of'] ?? null,
            ],
            'correlation_id' => $correlationId,
        ];

        /** @var WarCounterReimbursement $reimbursement */
        $reimbursement = DB::transaction(function () use (
            $counter,
            $nationId,
            $account,
            $resourceAdjustments,
            $resourcesCost,
            $unitLossCost,
            $infraLossCost,
            $totalCost,
            $note,
            $request,
            $correlationId,
            $meta,
            $transactionNote
        ): WarCounterReimbursement {
            $record = WarCounterReimbursement::query()->create([
                'war_counter_id' => $counter->id,
                'nation_id' => $nationId,
                'account_id' => $account->id,
                'reimbursed_by' => auth()->id(),
                'gasoline' => $resourceAdjustments['gasoline'],
                'munitions' => $resourceAdjustments['munitions'],
                'steel' => $resourceAdjustments['steel'],
                'aluminum' => $resourceAdjustments['aluminum'],
                'resources_cost' => $resourcesCost,
                'unit_loss_cost' => $unitLossCost,
                'infra_loss_cost' => $infraLossCost,
                'total_cost' => $totalCost,
                'note' => $note,
                'meta' => $meta,
            ]);

            $manualTransaction = AccountService::adjustAccountBalance(
                $account,
                [
                    'money' => $totalCost,
                    'gasoline' => $resourceAdjustments['gasoline'],
                    'munitions' => $resourceAdjustments['munitions'],
                    'steel' => $resourceAdjustments['steel'],
                    'aluminum' => $resourceAdjustments['aluminum'],
                    'note' => $transactionNote,
                ],
                auth()->id(),
                $request->ip(),
                [
                    'correlation_id' => $correlationId,
                    'war_counter_id' => $counter->id,
                    'war_counter_reimbursement_id' => $record->id,
                    'nation_id' => $nationId,
                    'category_breakdown' => [
                        'resource_amounts' => $resourceAdjustments,
                        'resource_value_cost' => $resourcesCost,
                        'resources_cost' => $resourcesCost,
                        'unit_loss_cost' => $unitLossCost,
                        'infra_loss_cost' => $infraLossCost,
                        'money_total' => $totalCost,
                    ],
                ]
            );

            $record->update([
                'manual_transaction_id' => $manualTransaction->id,
            ]);

            return $record->fresh(['nation', 'account', 'manualTransaction']);
        });

        event(new AllianceExpenseOccurred((new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'counter_reimbursement',
            description: sprintf('Counter reimbursement for Nation #%d (Counter #%d)', $nationId, $counter->id),
            date: now(),
            nationId: $nationId,
            accountId: $account->id,
            source: $reimbursement,
            money: $totalCost,
            gasoline: $resourceAdjustments['gasoline'],
            munitions: $resourceAdjustments['munitions'],
            steel: $resourceAdjustments['steel'],
            aluminum: $resourceAdjustments['aluminum'],
            meta: [
                'counter_id' => $counter->id,
                'war_counter_reimbursement_id' => $reimbursement->id,
                'resource_amounts' => $resourceAdjustments,
                'resource_value_cost' => $resourcesCost,
                'resources_cost' => $resourcesCost,
                'unit_loss_cost' => $unitLossCost,
                'infra_loss_cost' => $infraLossCost,
                'correlation_id' => $correlationId,
            ]
        ))->toArray()));

        $auditLogger->recordAfterCommit(
            category: 'war_counter',
            action: 'counter_reimbursement_recorded',
            outcome: 'success',
            severity: 'info',
            subject: $reimbursement,
            context: [
                'related' => [
                    ['type' => 'WarCounter', 'id' => (string) $counter->id, 'role' => 'counter'],
                    ['type' => 'Nation', 'id' => (string) $nationId, 'role' => 'recipient'],
                    ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'credited_account'],
                ],
                'data' => [
                    'resource_amounts' => $resourceAdjustments,
                    'resource_value_cost' => $resourcesCost,
                    'resources_cost' => $resourcesCost,
                    'unit_loss_cost' => $unitLossCost,
                    'infra_loss_cost' => $infraLossCost,
                    'total_cost' => $totalCost,
                    'correlation_id' => $correlationId,
                ],
            ],
            message: 'Counter reimbursement recorded.'
        );

        $auditLogger->recordAfterCommit(
            category: 'finance',
            action: 'counter_reimbursement_paid',
            outcome: 'success',
            severity: 'info',
            subject: $reimbursement->manualTransaction,
            context: [
                'related' => [
                    ['type' => 'WarCounterReimbursement', 'id' => (string) $reimbursement->id, 'role' => 'reimbursement_record'],
                    ['type' => 'WarCounter', 'id' => (string) $counter->id, 'role' => 'counter'],
                    ['type' => 'Account', 'id' => (string) $account->id, 'role' => 'credited_account'],
                ],
                'data' => [
                    'nation_id' => $nationId,
                    'money' => $totalCost,
                    'gasoline' => $resourceAdjustments['gasoline'],
                    'munitions' => $resourceAdjustments['munitions'],
                    'steel' => $resourceAdjustments['steel'],
                    'aluminum' => $resourceAdjustments['aluminum'],
                    'correlation_id' => $correlationId,
                ],
            ],
            message: 'Counter reimbursement paid into member account.'
        );

        return Redirect::back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Counter reimbursement saved and credited successfully.');
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

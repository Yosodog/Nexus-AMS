<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SpyCampaignAllianceRole;
use App\Enums\SpyOperationType;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateSpyAssignmentsJob;
use App\Jobs\SendSpyAssignmentsNotificationJob;
use App\Models\SpyCampaign;
use App\Models\SpyCampaignAlliance;
use App\Models\SpyRound;
use App\Services\Spy\SpyCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class SpyCampaignController extends Controller
{
    public function index(): View
    {
        $this->authorize('view-spies');

        $campaigns = SpyCampaign::query()
            ->withCount(['rounds', 'assignments', 'alliances'])
            ->with(['rounds.assignments'])
            ->latest()
            ->get();

        $stats = $campaigns->map(function (SpyCampaign $campaign) {
            $latestRound = $campaign->rounds->sortByDesc('round_number')->first();
            $avgOdds = $latestRound?->assignments->avg('calculated_odds') ?? 0;
            $highImpactRatio = $latestRound?->assignments
                ->where('expected_impact', '>', 100)
                ->count() ?? 0;

            return [
                'id' => $campaign->id,
                'avg_odds' => round($avgOdds, 2),
                'total_assignments' => $campaign->assignments_count,
                'high_impact_ratio' => $campaign->assignments_count > 0
                    ? round(($highImpactRatio / $campaign->assignments_count) * 100, 1)
                    : 0,
            ];
        });

        return view('admin.spy-campaigns.index', [
            'campaigns' => $campaigns,
            'stats' => $stats,
            'opTypes' => SpyOperationType::cases(),
        ]);
    }

    public function show(SpyCampaign $spyCampaign): View
    {
        $this->authorize('view-spies');

        $spyCampaign->load([
            'alliances.alliance',
            'rounds.assignments.attacker',
            'rounds.assignments.defender',
        ]);

        $latestRound = $spyCampaign->rounds->sortByDesc('round_number')->first();

        $oddsDistribution = $latestRound?->assignments->pluck('calculated_odds')->values() ?? collect();
        $impactSeries = $latestRound?->assignments->pluck('expected_impact')->values() ?? collect();
        $slotUsage = $this->buildSlotUsage($latestRound?->assignments);
        $topTargets = $latestRound?->assignments
            ->sortByDesc('expected_impact')
            ->take(10)
            ->map(fn ($assignment) => [
                'defender' => $assignment->defender?->leader_name,
                'impact' => $assignment->expected_impact,
                'odds' => $assignment->calculated_odds,
            ]) ?? collect();

        return view('admin.spy-campaigns.show', [
            'campaign' => $spyCampaign,
            'latestRound' => $latestRound,
            'oddsDistribution' => $oddsDistribution,
            'impactSeries' => $impactSeries,
            'slotUsage' => $slotUsage,
            'topTargets' => $topTargets,
            'opTypes' => SpyOperationType::cases(),
        ]);
    }

    public function store(Request $request, SpyCampaignService $service): RedirectResponse
    {
        $this->authorize('manage-spies');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'settings.min_success_chance' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $campaign = $service->create($data);

        return Redirect::route('admin.spy-campaigns.show', $campaign)
            ->with('alert-type', 'success')
            ->with('alert-message', 'Spy campaign created.');
    }

    public function update(
        Request $request,
        SpyCampaign $spyCampaign,
        SpyCampaignService $service
    ): RedirectResponse {
        $this->authorize('manage-spies');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string'],
            'settings.min_success_chance' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $service->update($spyCampaign, $data);

        return $this->redirectToCampaignWithTab($request, $spyCampaign)
            ->with('alert-type', 'success')
            ->with('alert-message', 'Campaign updated.');
    }

    public function addAlliance(Request $request, SpyCampaign $spyCampaign, SpyCampaignService $service): RedirectResponse
    {
        $this->authorize('manage-spies');

        $data = $request->validate([
            'alliance_id' => ['required', 'exists:alliances,id'],
            'role' => ['required', 'in:ally,enemy'],
        ]);

        $service->addAlliance($spyCampaign, (int) $data['alliance_id'], SpyCampaignAllianceRole::from($data['role']));

        return $this->redirectToCampaignWithTab($request, $spyCampaign)
            ->with('alert-type', 'success')
            ->with('alert-message', 'Alliance added.');
    }

    public function removeAlliance(
        Request $request,
        SpyCampaign $spyCampaign,
        SpyCampaignAlliance $spyCampaignAlliance,
        SpyCampaignService $service
    ): RedirectResponse {
        $this->authorize('manage-spies');

        if ($spyCampaignAlliance->spy_campaign_id !== $spyCampaign->id) {
            abort(404);
        }

        $service->removeAlliance($spyCampaignAlliance);

        return $this->redirectToCampaignWithTab($request, $spyCampaign)
            ->with('alert-type', 'success')
            ->with('alert-message', 'Alliance removed.');
    }

    public function addRound(Request $request, SpyCampaign $spyCampaign, SpyCampaignService $service): RedirectResponse
    {
        $this->authorize('manage-spies');

        $data = $request->validate([
            'op_type' => ['required', 'in:'.implode(',', SpyOperationType::values())],
            'round_number' => ['nullable', 'integer', 'min:1'],
            'min_success_chance' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $service->addRound($spyCampaign, $data);

        return $this->redirectToCampaignWithTab($request, $spyCampaign)
            ->with('alert-type', 'success')
            ->with('alert-message', 'Round added.');
    }

    public function generate(Request $request, SpyRound $spyRound): RedirectResponse
    {
        $this->authorize('manage-spies');

        GenerateSpyAssignmentsJob::dispatch($spyRound->id);
        $campaign = $spyRound->campaign()->firstOrFail();

        return $this->redirectToCampaignWithTab($request, $campaign)
            ->with('alert-type', 'info')
            ->with('alert-message', 'Assignment generation queued.');
    }

    public function sendMessages(Request $request, SpyRound $spyRound): RedirectResponse
    {
        $this->authorize('manage-spies');

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        SendSpyAssignmentsNotificationJob::dispatch($spyRound->id, $data['message']);
        $campaign = $spyRound->campaign()->firstOrFail();

        return $this->redirectToCampaignWithTab($request, $campaign)
            ->with('alert-type', 'success')
            ->with('alert-message', 'Notifications queued.');
    }

    public function round(SpyRound $spyRound): View
    {
        $this->authorize('view-spies');

        $spyRound->load([
            'campaign',
            'assignments.attacker',
            'assignments.attacker.military',
            'assignments.defender',
            'assignments.defender.military',
        ]);

        $avgOdds = $spyRound->assignments->avg('calculated_odds') ?? 0;
        $highOdds = $spyRound->assignments->where('calculated_odds', '>=', 80)->count();
        $lowOdds = $spyRound->assignments->where('low_odds_flag', true)->count();

        return view('admin.spy-campaigns.round', [
            'round' => $spyRound,
            'campaign' => $spyRound->campaign,
            'assignments' => $spyRound->assignments,
            'avgOdds' => $avgOdds,
            'highOdds' => $highOdds,
            'lowOdds' => $lowOdds,
        ]);
    }

    /**
     * @param  Collection<int, \App\Models\SpyAssignment>|null  $assignments
     */
    protected function buildSlotUsage(?Collection $assignments): array
    {
        if (! $assignments) {
            return ['attackers' => [], 'defenders' => []];
        }

        $attackers = $assignments->groupBy('attacker_nation_id')->map->count();
        $defenders = $assignments->groupBy('defender_nation_id')->map->count();

        return [
            'attackers' => $attackers,
            'defenders' => $defenders,
        ];
    }

    protected function redirectToCampaignWithTab(Request $request, SpyCampaign $spyCampaign): RedirectResponse
    {
        $parameters = ['spyCampaign' => $spyCampaign];
        $activeTab = $this->resolveActiveTab($request);

        if ($activeTab !== null) {
            $parameters['tab'] = $activeTab;
        }

        return Redirect::route('admin.spy-campaigns.show', $parameters);
    }

    protected function resolveActiveTab(Request $request): ?string
    {
        $activeTab = $request->string('active_tab')->toString();

        if ($activeTab === '') {
            return null;
        }

        return in_array($activeTab, ['overview', 'rounds', 'alliances', 'settings'], true)
            ? $activeTab
            : null;
    }
}

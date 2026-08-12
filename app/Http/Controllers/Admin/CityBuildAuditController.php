<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProfitabilityContextUnavailable;
use App\Exceptions\ProfitabilityPricingUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegenerateMemberBuildRecommendationRequest;
use App\Jobs\RefreshNationBuildRecommendationJob;
use App\Models\Nation;
use App\Services\CityBuildAuditService;
use App\Services\Economy\EconomyRules;
use App\Services\NationBuildRecommendationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class CityBuildAuditController extends Controller
{
    private const MEMBERS_PER_PAGE = 25;

    public function __construct(
        private readonly CityBuildAuditService $cityBuildAuditService,
        private readonly NationBuildRecommendationService $recommendationService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('view-audits');

        $search = trim((string) $request->query('search'));
        $members = $this->memberQuery()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('nation_name', 'like', "%{$search}%")
                        ->orWhere('leader_name', 'like', "%{$search}%");

                    if (ctype_digit($search)) {
                        $query->orWhereKey((int) $search);
                    }
                });
            })
            ->orderBy('nation_name')
            ->paginate(self::MEMBERS_PER_PAGE)
            ->withQueryString();

        $audits = $members->getCollection()->mapWithKeys(fn (Nation $nation): array => [
            $nation->id => $this->cityBuildAuditService->auditNation($nation),
        ]);

        return view('admin.audits.city-builds.index', [
            'members' => $members,
            'audits' => $audits,
            'search' => $search,
            'summary' => [
                'members' => $audits->count(),
                'cities' => $audits->sum('city_count'),
                'matching_cities' => $audits->sum('matching_city_count'),
                'cities_needing_changes' => $audits->sum('cities_needing_changes'),
                'members_with_mixed_builds' => $audits->where('has_different_city_builds', true)->count(),
                'missing_recommendations' => $audits->whereIn('recommendation_status', ['missing', 'outdated'])->count(),
            ],
        ]);
    }

    public function regenerate(
        RegenerateMemberBuildRecommendationRequest $request,
        Nation $nation,
    ): RedirectResponse {
        abort_unless($this->recommendationService->isEligibleNation($nation), 404);

        try {
            [$marketPriceSnapshotId, $radiationSnapshotId] = $this->calculationInputs($nation);
            RefreshNationBuildRecommendationJob::dispatch(
                (int) $nation->id,
                $marketPriceSnapshotId,
                $radiationSnapshotId,
            );
        } catch (Throwable $exception) {
            Log::warning('Admin member build recommendation could not be queued', [
                'nation_id' => $nation->id,
                'actor_id' => $request->user()?->id,
                'exception' => $exception,
            ]);

            return back()->with([
                'alert-message' => 'Build calculation inputs are temporarily unavailable. The current recommendation remains active.',
                'alert-type' => 'error',
            ]);
        }

        return back()->with([
            'alert-message' => "Build recommendation queued for {$nation->nation_name}.",
            'alert-type' => 'success',
        ]);
    }

    public function regenerateAll(RegenerateMemberBuildRecommendationRequest $request): RedirectResponse
    {
        try {
            $prices = $this->recommendationService->getPriceSetForBatch();

            if ($prices->snapshotId === null) {
                throw new ProfitabilityPricingUnavailable;
            }

            $radiationSnapshot = $this->recommendationService->getRadiationSnapshotForBatch();
            $nationIds = $this->recommendationService->eligibleNationIds();
            $this->recommendationService->assertBatchCalculationContextAvailable($nationIds, $radiationSnapshot);
            $this->recommendationService->pruneIneligibleRecommendations($nationIds);

            foreach ($nationIds as $nationId) {
                RefreshNationBuildRecommendationJob::dispatch(
                    $nationId,
                    $prices->snapshotId,
                    $radiationSnapshot->id,
                );
            }
        } catch (Throwable $exception) {
            Log::warning('Admin alliance build recommendations could not be queued', [
                'actor_id' => $request->user()?->id,
                'exception' => $exception,
            ]);

            return back()->with([
                'alert-message' => 'Build calculation inputs are temporarily unavailable. Existing recommendations remain active.',
                'alert-type' => 'error',
            ]);
        }

        return back()->with([
            'alert-message' => 'Queued build recommendations for '.count($nationIds).' members.',
            'alert-type' => 'success',
        ]);
    }

    /** @return array{int, int} */
    private function calculationInputs(Nation $nation): array
    {
        $prices = $this->recommendationService->getPriceSetForBatch();

        if ($prices->snapshotId === null) {
            throw new ProfitabilityPricingUnavailable;
        }

        $radiationSnapshot = $this->recommendationService->getRadiationSnapshotForBatch();
        $this->recommendationService->assertCalculationContextAvailable($nation, $radiationSnapshot);

        if ($radiationSnapshot->id === null) {
            throw new ProfitabilityContextUnavailable('A persisted radiation snapshot is required.');
        }

        return [$prices->snapshotId, (int) $radiationSnapshot->id];
    }

    /** @return Builder<Nation> */
    private function memberQuery(): Builder
    {
        $query = Nation::query()
            ->select([
                'id',
                'alliance_id',
                'alliance_position',
                'vacation_mode_turns',
                'nation_name',
                'leader_name',
                'num_cities',
                'score',
                'treasure_income_modifier',
                'color_turn_bonus',
                'economy_context_synced_at',
                'updated_at',
            ])
            ->with([
                'cities' => fn ($query) => $query->select([
                    'id',
                    'nation_id',
                    'name',
                    'infrastructure',
                    'land',
                    'powered',
                    ...EconomyRules::BUILD_FIELDS,
                ])->orderBy('id'),
                'buildRecommendation',
            ]);

        return $this->recommendationService->applyEligibilityToQuery($query);
    }
}

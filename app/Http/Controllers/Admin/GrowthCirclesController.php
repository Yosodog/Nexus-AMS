<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveGrowthCirclesSettingsRequest;
use App\Models\GrowthCircleDistribution;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use App\Services\AuditLogger;
use App\Services\GrowthCircleService;
use App\Services\SettingService;
use App\Services\TaxBracketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GrowthCirclesController extends Controller
{
    public function __construct(
        protected GrowthCircleService $growthCircles,
        protected AuditLogger $auditLogger,
    ) {}

    public function index(): View
    {
        $this->authorize('view-growth-circles');

        $resourceKeys = GrowthCircleDistribution::distributionResourceKeys();
        $resourceLabels = GrowthCircleDistribution::distributionResourceLabels();

        $enrollments = GrowthCircleEnrollment::query()
            ->with(['nation', 'account'])
            ->orderBy('enrolled_at')
            ->get()
            ->map(function (GrowthCircleEnrollment $enrollment) use ($resourceKeys): array {
                $eligibility = $enrollment->nation
                    ? $this->growthCircles->evaluateEligibility($enrollment->nation)
                    : ['eligible' => false, 'reason' => 'Nation no longer exists.'];

                $last = GrowthCircleDistribution::query()
                    ->where('nation_id', $enrollment->nation_id)
                    ->orderByDesc('cycle_date')
                    ->first();

                $sevenDayTotal = GrowthCircleDistribution::query()
                    ->where('nation_id', $enrollment->nation_id)
                    ->where('cycle_date', '>=', now()->subDays(7)->toDateString())
                    ->selectRaw(collect($resourceKeys)->map(fn (string $resource): string => "COALESCE(SUM({$resource}), 0) as {$resource}")->implode(', '))
                    ->first();

                $sevenDayResources = collect($resourceKeys)
                    ->mapWithKeys(fn (string $resource): array => [
                        $resource => (float) ($sevenDayTotal->{$resource} ?? 0),
                    ])
                    ->all();

                return [
                    'enrollment' => $enrollment,
                    'eligibility' => $eligibility,
                    'last' => $last,
                    'seven_day_resources' => $sevenDayResources,
                ];
            });

        return view('admin.growth-circles.index', [
            'taxId' => SettingService::getGrowthCirclesTaxId(),
            'fallbackTaxId' => SettingService::getGrowthCirclesFallbackTaxId(),
            'resourceLabels' => $resourceLabels,
            'rows' => $enrollments,
        ]);
    }

    public function history(Request $request): View
    {
        $this->authorize('view-growth-circles');

        $query = GrowthCircleDistribution::query()
            ->with(['nation:id,nation_name', 'account:id,name'])
            ->orderByDesc('cycle_date')
            ->orderByDesc('id');

        if ($from = $request->query('from')) {
            $query->where('cycle_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('cycle_date', '<=', $to);
        }
        if ($nationId = $request->query('nation_id')) {
            $query->where('nation_id', (int) $nationId);
        }
        if ($accountId = $request->query('account_id')) {
            $query->where('account_id', (int) $accountId);
        }

        return view('admin.growth-circles.history', [
            'resourceLabels' => GrowthCircleDistribution::distributionResourceLabels(),
            'rows' => $query->paginate(50)->withQueryString(),
        ]);
    }

    public function saveSettings(SaveGrowthCirclesSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $previous = [
            'growth_circles_tax_id' => SettingService::getGrowthCirclesTaxId(),
            'growth_circles_fallback_tax_id' => SettingService::getGrowthCirclesFallbackTaxId(),
        ];

        SettingService::setGrowthCirclesTaxId((int) $validated['growth_circles_tax_id']);
        SettingService::setGrowthCirclesFallbackTaxId((int) $validated['growth_circles_fallback_tax_id']);

        $this->auditLogger->success(
            category: 'growth_circles',
            action: 'settings_updated',
            context: [
                'changes' => [
                    'growth_circles_tax_id' => [
                        'from' => $previous['growth_circles_tax_id'],
                        'to' => (int) $validated['growth_circles_tax_id'],
                    ],
                    'growth_circles_fallback_tax_id' => [
                        'from' => $previous['growth_circles_fallback_tax_id'],
                        'to' => (int) $validated['growth_circles_fallback_tax_id'],
                    ],
                ],
            ],
        );

        return back()->with([
            'alert-message' => 'Growth Circles settings saved.',
            'alert-type' => 'success',
        ]);
    }

    public function forceDisenroll(Nation $nation): RedirectResponse
    {
        $this->authorize('manage-growth-circles');

        $this->growthCircles->disenroll($nation, logAudit: false);

        $this->auditLogger->success(
            category: 'growth_circles',
            action: 'admin_disenrolled',
            subject: $nation,
            context: ['data' => ['nation_id' => $nation->id]],
            message: "Admin force-disenrolled nation {$nation->nation_name} from Growth Circles.",
        );

        return back()->with([
            'alert-message' => "Force-disenrolled {$nation->nation_name} from Growth Circles.",
            'alert-type' => 'success',
        ]);
    }

    public function reapplyBracket(Nation $nation): RedirectResponse
    {
        $this->authorize('manage-growth-circles');
        $this->authorize('view-diagnostic-info');

        $taxId = SettingService::getGrowthCirclesTaxId();
        if ($taxId <= 0) {
            return back()->with([
                'alert-message' => 'Growth Circles tax bracket is not configured.',
                'alert-type' => 'error',
            ]);
        }

        $mutation = new TaxBracketService;
        $mutation->id = $taxId;
        $mutation->target_id = (int) $nation->id;
        $mutation->send();

        $this->auditLogger->success(
            category: 'growth_circles',
            action: 'bracket_reapplied',
            subject: $nation,
            context: ['data' => ['nation_id' => $nation->id, 'tax_id' => $taxId]],
            message: "Re-applied Growth Circles tax bracket for nation {$nation->nation_name}.",
        );

        return back()->with([
            'alert-message' => "Re-applied Growth Circles tax bracket for {$nation->nation_name}.",
            'alert-type' => 'success',
        ]);
    }
}

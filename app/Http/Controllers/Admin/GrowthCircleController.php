<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\GrowthCircleDistribution;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use App\Services\GrowthCircleService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GrowthCircleController extends Controller
{
    public function __construct(private readonly GrowthCircleService $service) {}

    public function index(): View
    {
        $this->authorize('view-growth-circles');

        $enrollments = GrowthCircleEnrollment::query()
            ->with('nation')
            ->orderByDesc('suspended')
            ->orderBy('enrolled_at')
            ->paginate(50);

        return view('admin.growth-circles.index', [
            'enrollments' => $enrollments,
            'growthCirclesEnabled' => SettingService::isGrowthCirclesEnabled(),
            'growthCircleTaxId' => SettingService::getGrowthCircleTaxId(),
            'growthCircleFallbackTaxId' => SettingService::getGrowthCircleFallbackTaxId(),
            'growthCircleSourceAccountId' => SettingService::getGrowthCircleSourceAccountId(),
            'growthCircleFoodPerCity' => SettingService::getGrowthCircleFoodPerCity(),
            'growthCircleUraniumPerCity' => SettingService::getGrowthCircleUraniumPerCity(),
            'growthCircleDiscordChannelId' => SettingService::getGrowthCircleDiscordChannelId(),
            'sourceAccounts' => Account::query()->whereNull('nation_id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function remove(Nation $nation): RedirectResponse
    {
        $this->authorize('manage-growth-circles');

        $this->service->remove($nation);

        return redirect()->route('admin.growth-circles.index')->with([
            'alert-message' => "{$nation->nation_name} has been removed from Growth Circles.",
            'alert-type' => 'success',
        ]);
    }

    public function clearSuspension(GrowthCircleEnrollment $enrollment): RedirectResponse
    {
        $this->authorize('manage-growth-circles');

        $this->service->clearSuspension($enrollment);

        return redirect()->route('admin.growth-circles.index')->with([
            'alert-message' => 'Suspension cleared. Distributions will resume on the next cycle.',
            'alert-type' => 'success',
        ]);
    }

    public function distributions(Nation $nation): View
    {
        $this->authorize('view-growth-circles');

        $distributions = GrowthCircleDistribution::query()
            ->where('nation_id', $nation->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('admin.growth-circles.distributions', compact('nation', 'distributions'));
    }
}

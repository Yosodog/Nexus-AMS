<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BeigeAlertSettingsRequest;
use App\Http\Requests\Admin\StoreBeigeAlertAllianceRequest;
use App\Models\BeigeAlertAlliance;
use App\Models\Nation;
use App\Services\AuditLogger;
use App\Services\BeigeAlertService;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BeigeAlertController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(BeigeAlertService $beigeAlertService): View
    {
        $this->authorize('view-raids');

        $trackedAlliances = BeigeAlertAlliance::query()
            ->with('alliance')
            ->orderBy('alliance_id')
            ->get();

        $trackedAllianceIds = $trackedAlliances->pluck('alliance_id');
        $beigeCounts = collect();
        $nextTurnCounts = collect();

        $beigeNations = collect();
        $beigeTurnsBreakdown = collect();

        if ($trackedAllianceIds->isNotEmpty()) {
            $beigeCounts = Nation::query()
                ->selectRaw('alliance_id, COUNT(*) as aggregate')
                ->whereIn('alliance_id', $trackedAllianceIds)
                ->where('beige_turns', '>', 0)
                ->groupBy('alliance_id')
                ->pluck('aggregate', 'alliance_id');

            $nextTurnCounts = Nation::query()
                ->selectRaw('alliance_id, COUNT(*) as aggregate')
                ->whereIn('alliance_id', $trackedAllianceIds)
                ->where('beige_turns', 1)
                ->groupBy('alliance_id')
                ->pluck('aggregate', 'alliance_id');

            $beigeNations = Nation::query()
                ->whereIn('alliance_id', $trackedAllianceIds)
                ->where('beige_turns', '>', 0)
                ->with(['alliance', 'military'])
                ->orderBy('beige_turns')
                ->orderByDesc('score')
                ->get();

            $beigeTurnsBreakdown = $beigeNations
                ->groupBy('beige_turns')
                ->map(fn ($nations) => $nations->count())
                ->sortKeys();
        }

        $totalBeigeNations = (int) $beigeNations->count();
        $nextTurnLeavers = (int) $beigeNations->where('beige_turns', 1)->count();
        $avgScore = $totalBeigeNations > 0
            ? round((float) $beigeNations->avg('score'), 2)
            : 0.0;
        $nextTurnChangeAt = $beigeAlertService->nextTurnChangeAt(CarbonImmutable::now());

        return view('admin.defense.beige-alerts', [
            'enabled' => SettingService::isBeigeAlertsEnabled(),
            'channelId' => SettingService::getBeigeAlertsDiscordChannelId(),
            'trackedAlliances' => $trackedAlliances,
            'beigeCounts' => $beigeCounts,
            'nextTurnCounts' => $nextTurnCounts,
            'beigeNations' => $beigeNations,
            'totalBeigeNations' => $totalBeigeNations,
            'nextTurnLeavers' => $nextTurnLeavers,
            'avgScore' => $avgScore,
            'nextTurnChangeAt' => $nextTurnChangeAt,
            'beigeTurnsBreakdown' => $beigeTurnsBreakdown,
        ]);
    }

    public function updateSettings(BeigeAlertSettingsRequest $request): RedirectResponse
    {
        $this->authorize('manage-raids');

        $previousEnabled = SettingService::isBeigeAlertsEnabled();
        $previousChannel = SettingService::getBeigeAlertsDiscordChannelId();
        $validated = $request->validated();

        $enabled = (bool) $validated['beige_alerts_enabled'];
        $channelId = trim((string) ($validated['beige_alerts_discord_channel_id'] ?? ''));

        SettingService::setBeigeAlertsEnabled($enabled);
        SettingService::setBeigeAlertsDiscordChannelId($channelId);

        $this->auditLogger->success(
            category: 'settings',
            action: 'beige_alert_settings_updated',
            context: [
                'changes' => [
                    'beige_alerts_enabled' => [
                        'from' => $previousEnabled,
                        'to' => $enabled,
                    ],
                    'beige_alerts_discord_channel_id' => [
                        'from' => $previousChannel,
                        'to' => $channelId,
                    ],
                ],
            ],
            message: 'Beige alert settings updated.'
        );

        return redirect()->route('admin.beige-alerts.index')->with([
            'alert-message' => 'Beige alert settings updated.',
            'alert-type' => 'success',
        ]);
    }

    public function storeAlliance(StoreBeigeAlertAllianceRequest $request): RedirectResponse
    {
        $this->authorize('manage-raids');

        $allianceId = (int) $request->validated('alliance_id');
        BeigeAlertAlliance::query()->create(['alliance_id' => $allianceId]);

        $this->auditLogger->success(
            category: 'settings',
            action: 'beige_alert_alliance_added',
            context: [
                'data' => [
                    'alliance_id' => $allianceId,
                ],
            ],
            message: 'Alliance added to beige alerts.'
        );

        return redirect()->route('admin.beige-alerts.index')->with([
            'alert-message' => 'Alliance added to beige alerts.',
            'alert-type' => 'success',
        ]);
    }

    public function destroyAlliance(BeigeAlertAlliance $beigeAlertAlliance): RedirectResponse
    {
        $this->authorize('manage-raids');

        $allianceId = (int) $beigeAlertAlliance->alliance_id;
        $beigeAlertAlliance->delete();

        $this->auditLogger->success(
            category: 'settings',
            action: 'beige_alert_alliance_removed',
            context: [
                'data' => [
                    'alliance_id' => $allianceId,
                ],
            ],
            message: 'Alliance removed from beige alerts.'
        );

        return redirect()->route('admin.beige-alerts.index')->with([
            'alert-message' => 'Alliance removed from beige alerts.',
            'alert-type' => 'success',
        ]);
    }
}

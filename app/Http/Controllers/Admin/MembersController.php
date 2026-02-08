<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InactivitySettingsRequest;
use App\Models\Nation;
use App\Services\AuditLogger;
use App\Services\MemberStatsService;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class MembersController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function index(MemberStatsService $statsService): View
    {
        $this->authorize('manage-accounts');

        return view('admin.members.index', $statsService->getOverviewData());
    }

    /**
     * @throws AuthorizationException
     */
    public function show(Nation $nation, MemberStatsService $service): View
    {
        $this->authorize('manage-accounts');

        return view('admin.members.show', $service->getNationStats($nation));
    }

    public function updateInactivitySettings(
        InactivitySettingsRequest $request,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $this->authorize('view-members');

        $previous = [
            'enabled' => SettingService::isInactivityModeEnabled(),
            'threshold_hours' => SettingService::getInactivityThresholdHours(),
            'actions' => SettingService::getInactivityActions(),
            'cooldown_hours' => SettingService::getInactivityCooldownHours(),
            'discord_channel_id' => SettingService::getInactivityDiscordChannelId(),
        ];

        $validated = $request->validated();
        $enabled = (bool) $validated['inactivity_enabled'];
        $thresholdHours = (int) $validated['inactivity_threshold_hours'];
        $cooldownHours = (int) $validated['inactivity_cooldown_hours'];
        $actions = $validated['inactivity_actions'] ?? [];
        $discordChannelId = $validated['inactivity_discord_channel_id'] ?? null;

        SettingService::setInactivityModeEnabled($enabled);
        SettingService::setInactivityThresholdHours($thresholdHours);
        SettingService::setInactivityCooldownHours($cooldownHours);
        SettingService::setInactivityActions($actions);
        SettingService::setInactivityDiscordChannelId($discordChannelId);

        $auditLogger->success(
            category: 'settings',
            action: 'inactivity_mode_updated',
            context: [
                'changes' => [
                    'enabled' => [
                        'from' => $previous['enabled'],
                        'to' => $enabled,
                    ],
                    'threshold_hours' => [
                        'from' => $previous['threshold_hours'],
                        'to' => $thresholdHours,
                    ],
                    'cooldown_hours' => [
                        'from' => $previous['cooldown_hours'],
                        'to' => $cooldownHours,
                    ],
                    'actions' => [
                        'from' => $previous['actions'],
                        'to' => $actions,
                    ],
                    'discord_channel_id' => [
                        'from' => $previous['discord_channel_id'],
                        'to' => $discordChannelId,
                    ],
                ],
            ],
            message: 'Inactivity mode settings updated.'
        );

        return redirect()->route('admin.members')->with([
            'alert-message' => 'Inactivity mode settings updated.',
            'alert-type' => 'success',
        ]);
    }

    public function runInactivityCheck(): RedirectResponse
    {
        $this->authorize('manage-accounts');

        Artisan::call('inactivity:check');

        return redirect()->route('admin.members')->with([
            'alert-message' => 'Inactivity check completed.',
            'alert-type' => 'success',
        ]);
    }
}

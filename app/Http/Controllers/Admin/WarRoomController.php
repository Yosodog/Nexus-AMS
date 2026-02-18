<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WarCounter;
use App\Models\WarPlan;
use App\Services\AuditLogger;
use App\Services\SettingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin dashboard aggregating counter and war plan states.
 */
class WarRoomController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Render the War Room dashboard.
     */
    public function index(Request $request): View
    {
        $this->authorize('view-wars');

        $activeCounterSearch = $this->nullIfEmpty($request->string('counter_active_search')->trim()->toString());
        $counterStatus = $request->query('counter_status', 'all');

        $planSearch = $this->nullIfEmpty($request->string('plan_search')->trim()->toString());
        $planStatus = $request->query('plan_status', 'all');

        return view('admin.war-room.index', [
            'counters' => $this->counterListing($activeCounterSearch, $counterStatus, 'counters_page'),
            'counterStatus' => $counterStatus,
            'plans' => $this->planListing($planSearch, $planStatus, 'plans_page'),
            'planStatus' => $planStatus,
            'counterSearch' => $activeCounterSearch,
            'planSearch' => $planSearch,
            'discordWarChannelId' => SettingService::getDiscordWarAlertChannelId(),
            'discordWarAlertsEnabled' => SettingService::isDiscordWarAlertEnabled(),
            'defaultWarRoomForumId' => SettingService::getDiscordWarRoomForumId(),
        ]);
    }

    /**
     * Update the Discord channel used for war alerts.
     */
    public function updateDiscordChannel(Request $request): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $previousChannel = SettingService::getDiscordWarAlertChannelId();
        $previousEnabled = SettingService::isDiscordWarAlertEnabled();
        $data = $request->validate([
            'channel_id' => ['nullable', 'string', 'max:190'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        SettingService::setDiscordWarAlertChannelId($data['channel_id'] ?? '');
        SettingService::setDiscordWarAlertEnabled($request->boolean('enabled'));

        $this->auditLogger->success(
            category: 'settings',
            action: 'war_alert_settings_updated',
            context: [
                'changes' => [
                    'discord_war_alert_channel_id' => [
                        'from' => $previousChannel,
                        'to' => $data['channel_id'] ?? '',
                    ],
                    'discord_war_alert_enabled' => [
                        'from' => $previousEnabled,
                        'to' => $request->boolean('enabled'),
                    ],
                ],
            ],
            message: 'War alert settings updated.'
        );

        return back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Discord war alert channel updated.');
    }

    /**
     * Update the default Discord forum used for war room thread creation.
     */
    public function updateDefaultWarRoomForum(Request $request): RedirectResponse
    {
        $this->authorize('manage-war-room');

        $previousForumId = SettingService::getDiscordWarRoomForumId();
        $data = $request->validate([
            'default_forum_channel_id' => ['nullable', 'string', 'max:190'],
        ]);

        SettingService::setDiscordWarRoomForumId($data['default_forum_channel_id'] ?? '');

        $this->auditLogger->success(
            category: 'settings',
            action: 'war_room_forum_settings_updated',
            context: [
                'changes' => [
                    'discord_war_room_forum_id' => [
                        'from' => $previousForumId,
                        'to' => $data['default_forum_channel_id'] ?? '',
                    ],
                ],
            ],
            message: 'War room forum settings updated.'
        );

        return back()
            ->with('alert-type', 'success')
            ->with('alert-message', 'Default Discord war room forum updated.');
    }

    /**
     * Query helper for counters with optional search.
     */
    protected function counterListing(?string $search, string $status, string $pageParam): LengthAwarePaginator
    {
        $query = WarCounter::query()
            ->with(['aggressor.alliance'])
            ->whereIn('status', ['active', 'draft'])
            ->latest('updated_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->whereHas('aggressor', function ($builder) use ($search) {
                $builder->where('leader_name', 'like', "%{$search}%")
                    ->orWhere('nation_name', 'like', "%{$search}%");
            });
        }

        return $query->paginate(10, ['*'], $pageParam)->withQueryString();
    }

    /**
     * Query helper for plans with optional search.
     */
    protected function planListing(?string $search, string $status, string $pageParam): LengthAwarePaginator
    {
        $query = WarPlan::query()
            ->withCount(['targets', 'assignments'])
            ->whereIn('status', ['planning', 'active'])
            ->latest('updated_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->paginate(10, ['*'], $pageParam)->withQueryString();
    }

    protected function nullIfEmpty(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}

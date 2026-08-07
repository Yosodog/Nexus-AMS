<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDiscordCityTierSettingsRequest;
use App\Http\Requests\Admin\UpdateDiscordDepartureRequest;
use App\Http\Requests\Admin\UpdateDiscordPrivateNotificationsRequest;
use App\Http\Requests\Admin\UpdateDiscordVerificationRequest;
use App\Services\AuditLogger;
use App\Services\Discord\PrivateNotificationService;
use App\Services\Settings\DiscordSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DiscordSettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DiscordSettings $settings,
        private readonly PrivateNotificationService $privateNotifications,
    ) {}

    public function index(): View
    {
        $this->authorize('view-diagnostic-info');

        return view('admin.settings.discord', [
            'discordVerificationRequired' => $this->settings->isVerificationRequired(),
            'discordDepartureChannelId' => $this->settings->getAllianceDepartureChannelId(),
            'discordDepartureEnabled' => $this->settings->isAllianceDepartureEnabled(),
            'discordPrivateNotificationsEnabled' => $this->settings->arePrivateNotificationsEnabled(),
            'discordCityTierBucketSize' => $this->settings->getCityTierBucketSize(),
        ]);
    }

    public function updateVerification(UpdateDiscordVerificationRequest $request): RedirectResponse
    {
        $previous = $this->settings->isVerificationRequired();
        $required = (bool) $request->validated('require_discord_verification');

        $this->settings->setVerificationRequired($required);

        $this->auditLogger->success(
            category: 'settings',
            action: 'discord_verification_requirement_updated',
            context: [
                'changes' => [
                    'require_discord_verification' => [
                        'from' => $previous,
                        'to' => $required,
                    ],
                ],
            ],
            message: 'Discord verification requirement updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => $required ? 'Discord verification is now required.' : 'Discord verification is now optional.',
            'alert-type' => 'success',
        ]);
    }

    public function updateDeparture(UpdateDiscordDepartureRequest $request): RedirectResponse
    {
        $previousEnabled = $this->settings->isAllianceDepartureEnabled();
        $previousChannel = $this->settings->getAllianceDepartureChannelId();
        $validated = $request->validated();
        $enabled = (bool) $validated['discord_alliance_departure_enabled'];
        $channelId = $validated['discord_alliance_departure_channel_id'] ?? null;

        DB::transaction(function () use ($enabled, $channelId): void {
            $this->settings->setAllianceDepartureEnabled($enabled);
            $this->settings->setAllianceDepartureChannelId($channelId);
        });

        $this->auditLogger->success(
            category: 'settings',
            action: 'discord_departure_settings_updated',
            context: [
                'changes' => [
                    'discord_alliance_departure_enabled' => [
                        'from' => $previousEnabled,
                        'to' => $enabled,
                    ],
                    'discord_alliance_departure_channel_id' => [
                        'from' => $previousChannel,
                        'to' => $channelId,
                    ],
                ],
            ],
            message: 'Discord departure settings updated.'
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Discord alliance departure settings updated.',
            'alert-type' => 'success',
        ]);
    }

    public function updatePrivateNotifications(UpdateDiscordPrivateNotificationsRequest $request): RedirectResponse
    {
        $previous = $this->settings->arePrivateNotificationsEnabled();
        $enabled = (bool) $request->validated('discord_private_notifications_enabled');

        $suppressedCount = DB::transaction(function () use ($enabled): int {
            $this->settings->setPrivateNotificationsEnabled($enabled);

            return $enabled ? 0 : $this->privateNotifications->suppressPending();
        });

        $this->auditLogger->success(
            category: 'settings',
            action: 'discord_private_notifications_updated',
            context: [
                'changes' => ['enabled' => ['from' => $previous, 'to' => $enabled]],
                'data' => ['suppressed_pending_count' => $suppressedCount],
            ],
            message: 'Discord private notification setting updated.',
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Discord private notification setting updated.',
            'alert-type' => 'success',
        ]);
    }

    public function updateCityTiers(UpdateDiscordCityTierSettingsRequest $request): RedirectResponse
    {
        $previous = $this->settings->getCityTierBucketSize();
        $bucketSize = (int) $request->validated('discord_city_tier_bucket_size');

        $this->settings->setCityTierBucketSize($bucketSize);

        $this->auditLogger->success(
            category: 'settings',
            action: 'discord_city_tier_settings_updated',
            context: [
                'changes' => ['bucket_size' => ['from' => $previous, 'to' => $bucketSize]],
            ],
            message: 'Discord city-tier settings updated.',
        );

        return redirect()->route('admin.settings')->with([
            'alert-message' => 'Discord city-tier settings updated. The next hourly sync will apply the new buckets.',
            'alert-type' => 'success',
        ]);
    }
}

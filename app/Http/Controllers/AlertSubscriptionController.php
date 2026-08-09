<?php

namespace App\Http\Controllers;

use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Enums\AlertSubscriptionType;
use App\Http\Requests\Alerts\StoreAlertSubscriptionRequest;
use App\Http\Requests\Alerts\UpdateAlertActivityReadRequest;
use App\Http\Requests\Alerts\UpdateAlertSubscriptionStatusRequest;
use App\Http\Requests\Alerts\UpdateAlertUserSettingsRequest;
use App\Http\Resources\Discord\AlertActivityResource;
use App\Models\AlertDelivery;
use App\Models\AlertOccurrence;
use App\Models\AlertSubscription;
use App\Models\User;
use App\Services\Alerts\AlertActivityService;
use App\Services\Alerts\AlertSubscriptionService;
use App\Services\Alerts\AlertUserSettingsService;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AlertSubscriptionController extends Controller
{
    public function index(
        Request $request,
        AlertSubscriptionService $alerts,
        AlertUserSettingsService $settings,
        AlertActivityService $activity,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $alerts->authorize($user);
        $userSettings = $settings->current($user);
        $activityPage = $activity->forUser($user, limit: 30);
        $subscriptions = AlertSubscription::query()
            ->whereBelongsTo($user)
            ->with('events')
            ->latest('id')
            ->get();

        return view('user.alerts.index', [
            'subscriptions' => $subscriptions,
            'nationEvents' => AlertSubscriptionType::Nation->events(),
            'allianceEvents' => AlertSubscriptionType::Alliance->events(),
            'resources' => AlertSubscriptionType::resources(),
            'settings' => $userSettings,
            'discordLinked' => $user->activeDiscordAccount() !== null,
            'notificationsEnabled' => $userSettings->discord_enabled,
            'activity' => collect($activityPage['items'])->map(fn (array $item): array => [
                ...$item,
                'event_label' => AlertActivityResource::eventLabel((string) $item['event_key']),
            ]),
            'maxActiveAlerts' => AlertSubscriptionService::MAX_ACTIVE_PER_USER,
            'timezones' => DateTimeZone::listIdentifiers(),
            'preview' => $request->session()->get('alert-preview'),
        ]);
    }

    public function store(
        StoreAlertSubscriptionRequest $request,
        AlertSubscriptionService $alerts,
        AlertActivityService $activity,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if (($validated['submit_action'] ?? 'save') === 'preview') {
            return redirect()->route('user.alerts.index')->withInput()->with([
                'alert-preview' => $alerts->previewForUser($user, $validated),
                'alert-message' => 'Preview generated. Nothing was saved or queued.',
                'alert-type' => 'info',
            ]);
        }

        if (($validated['submit_action'] ?? 'save') === 'test') {
            if (! $this->consumeTestAttempt($user)) {
                return redirect()->route('user.alerts.index')->withInput()->with([
                    'alert-message' => 'Too many alert tests. Wait a minute and try again.',
                    'alert-type' => 'warning',
                ]);
            }

            return $this->testRedirect(
                $user,
                $alerts->testDraft($user, $validated),
                $activity,
            );
        }

        $alerts->createForUser($user, $validated);

        return $this->redirect('Alert created. Its current value will be used as the baseline.');
    }

    public function update(
        StoreAlertSubscriptionRequest $request,
        AlertSubscription $alertSubscription,
        AlertSubscriptionService $alerts,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $alerts->updateForUser($user, $alertSubscription, $request->validated());

        return $this->redirect('Alert updated.');
    }

    public function updateSettings(
        UpdateAlertUserSettingsRequest $request,
        AlertSubscriptionService $alerts,
        AlertUserSettingsService $settings,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $alerts->authorize($user);
        $settings->update($user, $request->validated());

        return $this->redirect('Alert delivery preferences updated.');
    }

    public function markActivityRead(
        UpdateAlertActivityReadRequest $request,
        AlertDelivery $alertDelivery,
        AlertSubscriptionService $alerts,
        AlertActivityService $activity,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $alerts->authorize($user);
        $activity->markRead($user, $alertDelivery, $request->boolean('read'));

        return $this->redirect($request->boolean('read') ? 'Alert marked read.' : 'Alert marked unread.');
    }

    public function updateStatus(
        UpdateAlertSubscriptionStatusRequest $request,
        AlertSubscription $alertSubscription,
        AlertSubscriptionService $alerts,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $active = $request->boolean('is_active');
        $alerts->setActive($user, $alertSubscription, $active);

        return $this->redirect($active ? 'Alert resumed.' : 'Alert paused.');
    }

    public function destroy(
        Request $request,
        AlertSubscription $alertSubscription,
        AlertSubscriptionService $alerts,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $alerts->delete($user, $alertSubscription);

        return $this->redirect('Alert deleted.');
    }

    public function test(
        Request $request,
        AlertSubscription $alertSubscription,
        AlertSubscriptionService $alerts,
        AlertActivityService $activity,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        if (! $this->consumeTestAttempt($user)) {
            return $this->redirect('Too many alert tests. Wait a minute and try again.', 'warning');
        }

        return $this->testRedirect($user, $alerts->test($user, $alertSubscription), $activity);
    }

    private function testRedirect(
        User $user,
        AlertOccurrence $occurrence,
        AlertActivityService $activity,
    ): RedirectResponse {
        $discordDelivery = $occurrence->deliveries
            ->firstWhere('destination_kind', AlertDestinationKind::DiscordDm);
        if (! $discordDelivery instanceof AlertDelivery) {
            return $this->redirect('Test recorded in alert activity. Discord delivery was not requested.', 'info');
        }

        $receipt = $activity->deliveryForUser($user, $discordDelivery);

        return match ($discordDelivery->status) {
            AlertDeliveryStatus::Queued => $this->redirect(
                'Test queued. Its final Discord receipt will appear in alert activity.',
            ),
            AlertDeliveryStatus::Scheduled => $this->redirect(
                'Test scheduled. Its final Discord receipt will appear in alert activity.',
                'info',
            ),
            AlertDeliveryStatus::Delivered => $this->redirect('Test delivered to Discord.'),
            AlertDeliveryStatus::Suppressed => $this->redirect(
                'Test recorded but Discord delivery was suppressed: '.str_replace('_', ' ', (string) $receipt['reason_code']).'.',
                'warning',
            ),
            default => $this->redirect(
                'Test recorded, but Discord delivery is '.$discordDelivery->status->value.'. Review alert activity for recovery details.',
                'warning',
            ),
        };
    }

    private function consumeTestAttempt(User $user): bool
    {
        return RateLimiter::attempt(
            'alert-test:user:'.$user->id,
            3,
            fn (): bool => true,
            60,
        ) !== false;
    }

    private function redirect(string $message, string $type = 'success'): RedirectResponse
    {
        return redirect()->route('user.alerts.index')->with([
            'alert-message' => $message,
            'alert-type' => $type,
        ]);
    }
}

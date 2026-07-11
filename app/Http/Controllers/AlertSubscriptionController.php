<?php

namespace App\Http\Controllers;

use App\Enums\AlertSubscriptionType;
use App\Http\Requests\Alerts\StoreAlertSubscriptionRequest;
use App\Http\Requests\Alerts\UpdateAlertSubscriptionStatusRequest;
use App\Models\AlertSubscription;
use App\Models\User;
use App\Services\Alerts\AlertSubscriptionService;
use App\Services\Discord\PrivateNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AlertSubscriptionController extends Controller
{
    public function index(
        Request $request,
        AlertSubscriptionService $alerts,
        PrivateNotificationService $notifications,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $alerts->authorize($user);

        return view('user.alerts.index', [
            'subscriptions' => AlertSubscription::query()
                ->where('user_id', $user->id)
                ->latest()
                ->get(),
            'nationEvents' => AlertSubscriptionType::Nation->events(),
            'allianceEvents' => AlertSubscriptionType::Alliance->events(),
            'resources' => AlertSubscriptionType::resources(),
            'notificationsEnabled' => $notifications->canSend($user, 'watchlists'),
            'maxActiveAlerts' => AlertSubscriptionService::MAX_ACTIVE_PER_USER,
        ]);
    }

    public function store(
        StoreAlertSubscriptionRequest $request,
        AlertSubscriptionService $alerts,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $alerts->createForUser($user, $request->validated());

        return $this->redirect('Alert created. Its current value will be used as the baseline.');
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
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $alerts->test($user, $alertSubscription);

        return $this->redirect('Test alert queued for Discord delivery.');
    }

    private function redirect(string $message): RedirectResponse
    {
        return redirect()->route('user.alerts.index')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }
}

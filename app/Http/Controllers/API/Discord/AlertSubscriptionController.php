<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Requests\Alerts\StoreAlertSubscriptionRequest;
use App\Http\Requests\Alerts\UpdateAlertSubscriptionStatusRequest;
use App\Models\AlertSubscription;
use App\Models\User;
use App\Services\Alerts\AlertSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertSubscriptionController extends Controller
{
    use DiscordApiResponses;

    public function index(Request $request, AlertSubscriptionService $alerts): JsonResponse
    {
        $user = $this->actor($request);
        $alerts->authorize($user);

        return $this->discordData(AlertSubscription::query()
            ->where('user_id', $user->id)
            ->latest()
            ->get()
            ->map(fn (AlertSubscription $subscription): array => $this->serialize($subscription))
            ->all());
    }

    public function store(
        StoreAlertSubscriptionRequest $request,
        AlertSubscriptionService $alerts,
    ): JsonResponse {
        $subscription = $alerts->createForUser($this->actor($request), $request->validated());

        return $this->discordData($this->serialize($subscription), 201);
    }

    public function updateStatus(
        UpdateAlertSubscriptionStatusRequest $request,
        AlertSubscription $alertSubscription,
        AlertSubscriptionService $alerts,
    ): JsonResponse {
        $subscription = $alerts->setActive(
            $this->actor($request),
            $alertSubscription,
            $request->boolean('is_active'),
        );

        return $this->discordData($this->serialize($subscription));
    }

    public function destroy(
        Request $request,
        AlertSubscription $alertSubscription,
        AlertSubscriptionService $alerts,
    ): JsonResponse {
        $alerts->delete($this->actor($request), $alertSubscription);

        return $this->discordData(['deleted' => true]);
    }

    public function test(
        Request $request,
        AlertSubscription $alertSubscription,
        AlertSubscriptionService $alerts,
    ): JsonResponse {
        $alerts->test($this->actor($request), $alertSubscription);

        return $this->discordData(['queued' => true]);
    }

    /** @return array<string, mixed> */
    private function serialize(AlertSubscription $subscription): array
    {
        $config = $subscription->config;
        $condition = $subscription->type->value === 'market'
            ? sprintf('%s %s %s', ucfirst((string) $config['resource']), $config['direction'], number_format((float) $config['threshold'], 2))
            : implode(', ', $config['events'] ?? []);

        return [
            'id' => $subscription->id,
            'name' => $subscription->displayName(),
            'type' => $subscription->type->value,
            'type_label' => $subscription->type->label(),
            'target_id' => $config['target_id'] ?? null,
            'condition' => $condition,
            'active' => $subscription->is_active,
            'cooldown_minutes' => $subscription->cooldown_minutes,
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'last_evaluated_at' => $subscription->last_evaluated_at?->toIso8601String(),
            'last_triggered_at' => $subscription->last_triggered_at?->toIso8601String(),
            'deep_link_path' => '/user/alerts',
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);

        return $actor;
    }
}

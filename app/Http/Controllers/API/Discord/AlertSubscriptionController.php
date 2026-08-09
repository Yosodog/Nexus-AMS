<?php

namespace App\Http\Controllers\API\Discord;

use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Requests\Alerts\IndexAlertActivityRequest;
use App\Http\Requests\Alerts\StoreAlertSubscriptionRequest;
use App\Http\Requests\Alerts\UpdateAlertActivityReadRequest;
use App\Http\Requests\Alerts\UpdateAlertSubscriptionStatusRequest;
use App\Http\Requests\Alerts\UpdateAlertUserSettingsRequest;
use App\Http\Resources\Discord\AlertActivityResource;
use App\Http\Resources\Discord\AlertDeliveryResource;
use App\Http\Resources\Discord\AlertSubscriptionResource;
use App\Http\Resources\Discord\AlertUserSettingsResource;
use App\Models\AlertDelivery;
use App\Models\AlertOccurrence;
use App\Models\AlertSubscription;
use App\Models\User;
use App\Services\Alerts\AlertActivityService;
use App\Services\Alerts\AlertSubscriptionService;
use App\Services\Alerts\AlertUserSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AlertSubscriptionController extends Controller
{
    use DiscordApiResponses;

    private const CAPABILITIES = [
        'alerts.preferences.v2',
        'alerts.activity.v1',
        'alerts.test-delivery.v1',
    ];

    public function index(
        Request $request,
        AlertSubscriptionService $alerts,
        AlertUserSettingsService $settings,
    ): JsonResponse {
        $user = $this->actor($request);
        $alerts->authorize($user);
        $globalSettings = $settings->current($user);
        $discordLinked = $user->activeDiscordAccount() !== null;
        $subscriptions = $this->subscriptionQuery($user)->get();

        return $this->discordData(
            $subscriptions->map(fn (AlertSubscription $subscription): array => (new AlertSubscriptionResource(
                $subscription,
                $globalSettings->discord_enabled,
                $discordLinked,
                $alerts->eventKeysFor($subscription),
            ))->resolve($request))->all(),
            meta: ['capabilities' => self::CAPABILITIES],
        );
    }

    public function store(
        StoreAlertSubscriptionRequest $request,
        AlertSubscriptionService $alerts,
        AlertUserSettingsService $settings,
    ): JsonResponse {
        $user = $this->actor($request);
        $subscription = $alerts->createForUser($user, $request->validated());

        return $this->discordData(
            (new AlertSubscriptionResource(
                $subscription,
                $settings->isDiscordEnabled($user),
                $user->activeDiscordAccount() !== null,
                $alerts->eventKeysFor($subscription),
            ))->resolve($request),
            201,
            ['capabilities' => self::CAPABILITIES],
        );
    }

    public function update(
        StoreAlertSubscriptionRequest $request,
        AlertSubscription $alertSubscription,
        AlertSubscriptionService $alerts,
        AlertUserSettingsService $settings,
    ): JsonResponse {
        $user = $this->actor($request);
        $subscription = $alerts->updateForUser($user, $alertSubscription, $request->validated());

        return $this->discordData((new AlertSubscriptionResource(
            $subscription,
            $settings->isDiscordEnabled($user),
            $user->activeDiscordAccount() !== null,
            $alerts->eventKeysFor($subscription),
        ))->resolve($request));
    }

    public function updateStatus(
        UpdateAlertSubscriptionStatusRequest $request,
        AlertSubscription $alertSubscription,
        AlertSubscriptionService $alerts,
        AlertUserSettingsService $settings,
    ): JsonResponse {
        $user = $this->actor($request);
        $subscription = $alerts->setActive(
            $user,
            $alertSubscription,
            $request->boolean('is_active'),
        );

        return $this->discordData((new AlertSubscriptionResource(
            $subscription,
            $settings->isDiscordEnabled($user),
            $user->activeDiscordAccount() !== null,
            $alerts->eventKeysFor($subscription),
        ))->resolve($request));
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
        AlertActivityService $activity,
    ): JsonResponse {
        $user = $this->actor($request);
        if (($rateLimited = $this->rateLimitedTestResponse($user)) !== null) {
            return $rateLimited;
        }

        return $this->discordData($this->testResult(
            $user,
            $alerts->test($user, $alertSubscription),
            $activity,
        ));
    }

    public function preview(
        StoreAlertSubscriptionRequest $request,
        AlertSubscriptionService $alerts,
    ): JsonResponse {
        return $this->discordData(
            $alerts->previewForUser($this->actor($request), $request->validated()),
            meta: ['capabilities' => ['alerts.preferences.v2']],
        );
    }

    public function testDraft(
        StoreAlertSubscriptionRequest $request,
        AlertSubscriptionService $alerts,
        AlertActivityService $activity,
    ): JsonResponse {
        $user = $this->actor($request);
        if (($rateLimited = $this->rateLimitedTestResponse($user)) !== null) {
            return $rateLimited;
        }

        return $this->discordData(
            $this->testResult($user, $alerts->testDraft($user, $request->validated()), $activity),
            202,
            ['capabilities' => ['alerts.test-delivery.v1']],
        );
    }

    public function settings(
        Request $request,
        AlertSubscriptionService $alerts,
        AlertUserSettingsService $settings,
    ): JsonResponse {
        $user = $this->actor($request);
        $alerts->authorize($user);

        return $this->discordData(
            (new AlertUserSettingsResource($settings->current($user)))->resolve($request),
            meta: ['capabilities' => ['alerts.preferences.v2']],
        );
    }

    public function updateSettings(
        UpdateAlertUserSettingsRequest $request,
        AlertSubscriptionService $alerts,
        AlertUserSettingsService $settings,
    ): JsonResponse {
        $user = $this->actor($request);
        $alerts->authorize($user);

        return $this->discordData(
            (new AlertUserSettingsResource($settings->update($user, $request->validated())))->resolve($request),
            meta: ['capabilities' => ['alerts.preferences.v2']],
        );
    }

    public function activity(
        IndexAlertActivityRequest $request,
        AlertSubscriptionService $alerts,
        AlertActivityService $activity,
    ): JsonResponse {
        $user = $this->actor($request);
        $alerts->authorize($user);
        $page = $activity->forUser(
            $user,
            $request->integer('before_delivery_id') ?: null,
            $request->integer('limit', 30),
        );

        return $this->discordData([
            'items' => collect($page['items'])
                ->map(fn (array $item): array => (new AlertActivityResource($item))->resolve($request))
                ->all(),
            'next_cursor' => $page['next_cursor'],
        ], meta: ['capabilities' => ['alerts.activity.v1']]);
    }

    public function markActivityRead(
        UpdateAlertActivityReadRequest $request,
        AlertDelivery $alertDelivery,
        AlertSubscriptionService $alerts,
        AlertActivityService $activity,
    ): JsonResponse {
        $user = $this->actor($request);
        $alerts->authorize($user);
        $delivery = $activity->markRead($user, $alertDelivery, $request->boolean('read'));

        return $this->discordData([
            'activity_id' => $delivery->id,
            'read_at' => $delivery->read_at?->toIso8601String(),
        ]);
    }

    public function delivery(
        Request $request,
        AlertDelivery $alertDelivery,
        AlertSubscriptionService $alerts,
        AlertActivityService $activity,
    ): JsonResponse {
        $user = $this->actor($request);
        $alerts->authorize($user);

        return $this->discordData(
            (new AlertDeliveryResource($activity->deliveryForUser($user, $alertDelivery)))->resolve($request),
        );
    }

    /** @return Builder<AlertSubscription> */
    private function subscriptionQuery(User $user): Builder
    {
        $lastDiscordStatus = AlertDelivery::query()
            ->select('status')
            ->whereColumn('alert_subscription_id', 'alert_subscriptions.id')
            ->where('destination_kind', AlertDestinationKind::DiscordDm->value)
            ->latest('id')
            ->limit(1);
        $lastDiscordReason = AlertDelivery::query()
            ->select('reason_code')
            ->whereColumn('alert_subscription_id', 'alert_subscriptions.id')
            ->where('destination_kind', AlertDestinationKind::DiscordDm->value)
            ->latest('id')
            ->limit(1);

        return AlertSubscription::query()
            ->whereBelongsTo($user)
            ->withMax([
                'deliveries as last_discord_delivered_at' => fn (Builder $query): Builder => $query
                    ->where('destination_kind', AlertDestinationKind::DiscordDm->value)
                    ->where('status', AlertDeliveryStatus::Delivered->value),
            ], 'delivered_at')
            ->withMin([
                'deliveries as next_discord_delivery_at' => fn (Builder $query): Builder => $query
                    ->where('destination_kind', AlertDestinationKind::DiscordDm->value)
                    ->whereIn('status', [
                        AlertDeliveryStatus::Pending->value,
                        AlertDeliveryStatus::Scheduled->value,
                        AlertDeliveryStatus::Queued->value,
                    ])
                    ->whereNotNull('scheduled_at'),
            ], 'scheduled_at')
            ->addSelect([
                'last_discord_status' => $lastDiscordStatus,
                'last_discord_reason' => $lastDiscordReason,
            ])
            ->latest('id');
    }

    /** @return array<string, mixed> */
    private function testResult(
        User $user,
        AlertOccurrence $occurrence,
        AlertActivityService $activity,
    ): array {
        $deliveries = $occurrence->deliveries
            ->map(fn (AlertDelivery $delivery): array => $activity->deliveryForUser($user, $delivery))
            ->values();

        return [
            'success' => true,
            'queued' => $deliveries->contains(fn (array $delivery): bool => $delivery['status'] === AlertDeliveryStatus::Queued->value),
            'occurrence_id' => $occurrence->id,
            'event_key' => $occurrence->event_key,
            'is_test' => $occurrence->is_test,
            'delivery_ids' => $deliveries->pluck('id')->all(),
            'deliveries' => $deliveries->all(),
        ];
    }

    private function rateLimitedTestResponse(User $user): ?JsonResponse
    {
        $key = 'alert-test:user:'.$user->id;
        if (RateLimiter::attempt($key, 3, fn (): bool => true, 60) !== false) {
            return null;
        }

        $retryAfter = RateLimiter::availableIn($key);

        return $this->discordError(
            'rate_limited',
            'Too many alert tests. Try again shortly.',
            429,
            ['retry_after_seconds' => $retryAfter],
        )->header('Retry-After', (string) $retryAfter);
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);

        return $actor;
    }
}

<?php

namespace App\Services\Discord;

use App\DataTransferObjects\Discord\PrivateNotificationPayload;
use App\Enums\DiscordQueueStatus;
use App\Models\DiscordNotificationPreference;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Support\Str;

class PrivateNotificationService
{
    /** @var array<string, string> */
    public const CATEGORIES = [
        'applications' => 'Applications',
        'grants' => 'Grants',
        'loans' => 'Loans',
        'war_aid' => 'War aid',
        'rebuilding' => 'Rebuilding',
        'war_assignments' => 'War assignments',
        'spy_assignments' => 'Spy assignments',
        'audits' => 'Audit reminders',
        'watchlists' => 'Custom alerts and watchlists',
        'blockade_relief' => 'Blockade relief coordination',
    ];

    /** @var array<int, string> */
    private const SENSITIVE_SUMMARY_KEYS = [
        'amount', 'balance', 'code', 'denial_reason', 'note', 'reason', 'money',
        'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline',
        'munitions', 'steel', 'aluminum', 'food', 'credits', 'resources',
    ];

    /**
     * Preferences default off per category. Users must explicitly opt in, and the global setting remains a fail-closed master switch.
     *
     * @return array<string, bool>
     */
    public function preferencesFor(User $user): array
    {
        $stored = $user->discordNotificationPreferences()
            ->whereIn('category', array_keys(self::CATEGORIES))
            ->pluck('enabled', 'category');

        return collect(self::CATEGORIES)
            ->mapWithKeys(fn (string $label, string $category): array => [
                $category => $stored->has($category) ? (bool) $stored->get($category) : false,
            ])
            ->all();
    }

    /** @param array<string, bool> $preferences */
    public function updatePreferences(User $user, array $preferences): void
    {
        foreach (array_keys(self::CATEGORIES) as $category) {
            DiscordNotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'category' => $category],
                ['enabled' => (bool) ($preferences[$category] ?? false)],
            );
        }
    }

    public function canSend(User $user, string $category): bool
    {
        if (! SettingService::areDiscordPrivateNotificationsEnabled() || ! isset(self::CATEGORIES[$category])) {
            return false;
        }

        if ($user->disabled || ! $user->isVerified() || ! $user->activeDiscordAccount()) {
            return false;
        }

        $stored = $user->discordNotificationPreferences()
            ->where('category', $category)
            ->value('enabled');

        return $stored !== null && (bool) $stored;
    }

    /**
     * Suppress pending private messages without interfering with a worker's active lease.
     *
     * @param  array<int, string>|null  $categories
     */
    public function suppressPending(?User $user = null, ?array $categories = null): int
    {
        $discordId = $user?->activeDiscordAccount()?->discord_id;
        if ($user !== null && (! is_string($discordId) || $discordId === '')) {
            return 0;
        }

        $query = DiscordQueue::query()
            ->where('action', 'PRIVATE_NOTIFICATION')
            ->where('status', DiscordQueueStatus::Pending->value);

        if ($discordId !== null) {
            $query->where('payload->recipient_discord_id', $discordId);
        }

        if ($categories !== null) {
            $eventPrefixes = collect($categories)->flatMap(fn (string $category): array => match ($category) {
                'applications' => ['application_'],
                'grants' => ['grant_', 'city_grant_'],
                'loans' => ['loan_'],
                'war_aid' => ['war_aid_'],
                'rebuilding' => ['rebuilding_'],
                'war_assignments' => ['war_assignment_'],
                'spy_assignments' => ['spy_assignment_'],
                'audits' => ['audit_'],
                'watchlists' => ['watchlist_'],
                'blockade_relief' => ['blockade_relief_'],
                default => [],
            })->values();

            $query->where(function ($events) use ($eventPrefixes): void {
                foreach ($eventPrefixes as $prefix) {
                    $events->orWhere('payload->event_type', 'like', $prefix.'%');
                }
            });
        }

        return $query->update([
            'status' => DiscordQueueStatus::Complete->value,
            'result' => json_encode([
                'delivery' => 'suppressed',
                'reason' => $user === null ? 'master_disabled' : 'recipient_opt_out',
            ], JSON_THROW_ON_ERROR),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function canSendToNation(object $notifiable, string $category): bool
    {
        return $notifiable instanceof Nation
            && $notifiable->user !== null
            && $this->canSend($notifiable->user, $category);
    }

    /**
     * Queue one minimal private notification when both the master switch and recipient preference allow it.
     *
     * @param  array{type:string,id:int|string,label?:string}  $subject
     * @param  array<string, mixed>  $summary
     */
    public function enqueueForNation(
        Nation $nation,
        string $category,
        string $eventType,
        string $notificationId,
        array $subject,
        string $deepLinkPath,
        array $summary = [],
    ): bool {
        if (! $this->canSendToNation($nation, $category)) {
            return false;
        }

        app(DiscordQueueService::class)->enqueue(
            'PRIVATE_NOTIFICATION',
            $this->payloadForNation($nation, $eventType, $notificationId, $subject, $deepLinkPath, $summary)->toArray(),
            dedupeKey: 'private-notification:'.$notificationId,
        );

        return true;
    }

    /**
     * @param  array{type:string,id:int|string,label?:string}  $subject
     * @param  array<string, mixed>  $summary
     */
    public function payloadForNation(
        Nation $nation,
        string $eventType,
        string $notificationId,
        array $subject,
        string $deepLinkPath,
        array $summary = [],
    ): PrivateNotificationPayload {
        $discordId = $nation->user?->activeDiscordAccount()?->discord_id;

        if (! is_string($discordId) || $discordId === '') {
            throw new \LogicException('A linked Discord account is required for private notifications.');
        }

        $label = isset($subject['label']) ? Str::limit(strip_tags((string) $subject['label']), 80, '') : null;

        return new PrivateNotificationPayload(
            eventType: Str::limit($eventType, 100, ''),
            recipientDiscordId: $discordId,
            notificationId: $notificationId,
            subject: array_filter([
                'type' => Str::limit((string) $subject['type'], 64, ''),
                'id' => $subject['id'],
                'label' => $label,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            occurredAt: now(),
            deepLinkPath: $this->normalizeDeepLink($deepLinkPath),
            summary: $this->sanitizeSummary($summary),
        );
    }

    /** @param array<string, mixed> $summary
     * @return array<string, bool|int|float|string|null>
     */
    private function sanitizeSummary(array $summary): array
    {
        return collect($summary)
            ->reject(fn (mixed $value, string $key): bool => in_array(Str::snake($key), self::SENSITIVE_SUMMARY_KEYS, true))
            ->filter(fn (mixed $value): bool => is_scalar($value) || $value === null)
            ->take(10)
            ->mapWithKeys(function (mixed $value, string $key): array {
                $safeKey = Str::limit(Str::snake($key), 64, '');
                $safeValue = is_string($value) ? Str::limit(strip_tags($value), 160, '') : $value;

                return [$safeKey => $safeValue];
            })
            ->all();
    }

    private function normalizeDeepLink(string $path): string
    {
        $path = '/'.ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/');

        return Str::limit($path, 255, '');
    }
}

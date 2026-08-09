<?php

namespace App\Services\Alerts;

use App\Enums\AlertSubscriptionType;
use Illuminate\Validation\ValidationException;

class AlertSubscriptionEventMap
{
    /** @var array<string, array<string, string>> */
    private const EVENT_KEYS = [
        'nation' => [
            'alliance_changed' => 'nation.alliance.changed',
            'vacation_mode_entered' => 'nation.vacation.entered',
            'vacation_mode_exited' => 'nation.vacation.exited',
            'beige_exited' => 'nation.beige.exited',
            'city_count_changed' => 'nation.city_count.changed',
            'war_state_changed' => 'nation.active_wars.changed',
        ],
        'alliance' => [
            'membership_changed' => 'alliance.membership.changed',
            'treaty_changed' => 'alliance.treaty.changed',
        ],
        'market' => [
            'price_crossed' => 'market.price.crossed',
        ],
    ];

    public function __construct(private readonly AlertEventCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function eventKeys(AlertSubscriptionType $type, array $config): array
    {
        $aliases = self::EVENT_KEYS[$type->value];
        $requested = $type === AlertSubscriptionType::Market
            ? ['price_crossed']
            : array_values(array_unique(array_map('strval', $config['events'] ?? [])));
        $allowedKeys = array_values($aliases);
        $eventKeys = [];

        foreach ($requested as $event) {
            $eventKey = $aliases[$event] ?? (in_array($event, $allowedKeys, true) ? $event : null);
            if ($eventKey === null || ! isset($this->catalog->memberSubscriptionEvents()[$eventKey])) {
                throw ValidationException::withMessages([
                    'events' => 'One or more selected events are not supported for this alert type.',
                ]);
            }

            $eventKeys[] = $eventKey;
        }

        if ($eventKeys === []) {
            throw ValidationException::withMessages([
                'events' => 'Select at least one supported event.',
            ]);
        }

        return array_values(array_unique($eventKeys));
    }

    /**
     * Preserve the legacy config aliases while alert_subscription_events carries stable catalog keys.
     *
     * @param  list<string>  $eventKeys
     * @return list<string>
     */
    public function legacyEvents(AlertSubscriptionType $type, array $eventKeys): array
    {
        $legacyByEventKey = array_flip(self::EVENT_KEYS[$type->value]);
        $legacyEvents = [];

        foreach ($eventKeys as $eventKey) {
            if (! isset($legacyByEventKey[$eventKey])) {
                throw ValidationException::withMessages([
                    'events' => 'One or more selected events are not supported for this alert type.',
                ]);
            }

            $legacyEvents[] = $legacyByEventKey[$eventKey];
        }

        return array_values(array_unique($legacyEvents));
    }
}

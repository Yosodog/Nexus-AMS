<?php

namespace App\Services\Settings;

class MilitaryReadinessSettings
{
    public function __construct(private readonly SettingValueStore $settings) {}

    public function isAssistantEnabled(): bool
    {
        $value = $this->settings->get('mmr_assistant_enabled');

        if (is_null($value)) {
            $this->setAssistantEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public function setAssistantEnabled(bool $enabled): void
    {
        $this->settings->set('mmr_assistant_enabled', (int) $enabled);
    }

    /**
     * @return array<string, float>
     */
    public function getResourceWeights(): array
    {
        $raw = $this->settings->get('mmr_resource_weights');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return collect($decoded)
                    ->map(fn ($weight) => (float) $weight)
                    ->toArray();
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $weights
     */
    public function setResourceWeights(array $weights): void
    {
        $this->settings->set('mmr_resource_weights', json_encode($weights));
    }
}

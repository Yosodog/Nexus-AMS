<?php

namespace App\Services;

use App\Models\MMRTier;
use App\Models\Nation;
use App\Models\NationSignIn;

class MMRService
{
    private const RESOURCES = ['money', 'steel', 'aluminum', 'munitions', 'gasoline', 'uranium', 'food'];

    private const UNITS = [
        'soldiers' => ['multiplier' => 3000, 'field' => 'barracks'],
        'tanks' => ['multiplier' => 250, 'field' => 'factories'],
        'aircraft' => ['multiplier' => 15, 'field' => 'hangars'],
        'ships' => ['multiplier' => 5, 'field' => 'drydocks'],
        'missiles' => ['multiplier' => 1, 'field' => 'missiles'],
        // nukes spies handled separately
    ];

    public function getResourceFields(): array
    {
        return self::RESOURCES;
    }

    public function getResourceWeights(): array
    {
        $stored = SettingService::getMMRResourceWeights();
        $weights = [];

        foreach (self::RESOURCES as $resource) {
            $weights[$resource] = isset($stored[$resource]) ? max(0.0, (float) $stored[$resource]) : 0.0;
        }

        $total = array_sum($weights);

        if ($total <= 0) {
            return $this->defaultWeights();
        }

        if (abs($total - 100.0) <= 0.01) {
            return $weights;
        }

        return $this->normalizeWeights($weights);
    }

    public function getTierForCityCount(int $cityCount): ?MMRTier
    {
        return MMRTier::whereIn('city_count', [$cityCount, 0])
            ->orderByDesc('city_count') // Prefer exact match, fallback to 0
            ->first();
    }

    public function getTierForNation(Nation $nation): ?MMRTier
    {
        return $this->getTierForCityCount($nation->num_cities);
    }

    public function evaluate(Nation $nation, NationSignIn $signIn): array
    {
        $cityCount = (int) ($nation->num_cities ?? $signIn->num_cities ?? 0);
        $tier = $this->getTierForCityCount($cityCount);

        if (! $tier) {
            return [
                'mmr_score' => 0,
                'meets_unit_requirements' => false,
                'resource_breakdown' => [],
                'weights' => $this->defaultWeights(),
                'tier' => null,
                'meets_resource_requirements' => false,
            ];
        }

        $weights = $this->getResourceWeights();
        $resourceScore = $this->calculateResourceScore($signIn, $tier, $weights, $cityCount);
        $resourceCompliant = collect($resourceScore['breakdown'])->every(fn ($resource) => $resource['met'] || $resource['required'] <= 0);

        return [
            'mmr_score' => $resourceScore['score'],
            'meets_unit_requirements' => $this->meetsUnitRequirements($signIn, $tier, $cityCount),
            'meets_resource_requirements' => $resourceCompliant,
            'resource_breakdown' => $resourceScore['breakdown'],
            'weights' => $weights,
            'tier' => $tier,
        ];
    }

    /**
     * Calculates the MMR resource score (0-100) based on sign-in and banked amounts.
     */
    protected function calculateResourceScore(NationSignIn $signIn, MMRTier $tier, array $weights, int $cityCount): array
    {
        $score = 0;
        $breakdown = [];

        foreach (self::RESOURCES as $resource) {
            $have = (float) $signIn->$resource; // Already includes nation + banked
            $required = (float) $tier->$resource * max(1, $cityCount);
            $weight = $weights[$resource] ?? 0.0;

            if ($required <= 0) {
                $progress = 1;
            } else {
                $progress = min(1, $have / $required);
            }

            $score += $weight * $progress;

            $breakdown[$resource] = [
                'have' => $have,
                'required' => $required,
                'weight' => $weight,
                'progress' => $progress,
                'met' => $progress >= 1,
            ];
        }

        return [
            'score' => (int) round($score),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Determines if the nation meets the unit-based MMR thresholds.
     */
    protected function meetsUnitRequirements(NationSignIn $signIn, MMRTier $tier, int $cityCount): bool
    {
        foreach ($this->buildUnitRequirements($tier, $cityCount) as $unit => $required) {
            if ($required > ($signIn->$unit ?? 0)) {
                return false;
            }
        }

        return true;
    }

    public function buildUnitRequirements(MMRTier $tier, int $cityCount): array
    {
        $requirements = [];

        foreach (self::UNITS as $unit => $info) {
            $requirements[$unit] = $tier->{$info['field']} * $info['multiplier'] * $cityCount;
        }

        $requirements['nukes'] = $tier->nukes;
        $requirements['spies'] = $tier->spies;

        return $requirements;
    }

    protected function normalizeWeights(array $weights): array
    {
        $normalized = [];
        $total = collect(self::RESOURCES)->sum(fn ($resource) => max(0.0, (float) ($weights[$resource] ?? 0)));

        if ($total <= 0) {
            return $this->defaultWeights();
        }

        foreach (self::RESOURCES as $resource) {
            $normalized[$resource] = ((float) ($weights[$resource] ?? 0) / $total) * 100;
        }

        return $normalized;
    }

    protected function defaultWeights(): array
    {
        $perResource = round(100 / count(self::RESOURCES), 2);
        $weights = array_fill_keys(self::RESOURCES, $perResource);
        $weights[self::RESOURCES[0]] += 100 - array_sum($weights);

        return $weights;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Arr;

class RaidNationSnapshotCompactor
{
    private const STORED_FIELDS = [
        'id', 'nation_name', 'leader_name', 'alliance_id', 'alliance', 'score', 'last_active',
        'color', 'beige_turns', 'vacation_mode_turns', 'offensive_wars_count', 'defensive_wars_count',
        'active_wars', 'bounties', 'history_complete', 'soldiers', 'tanks', 'aircraft', 'ships',
        'missiles', 'nukes', 'war_policy', 'is_fortified', 'fortified', 'military_research', 'projects',
        'imperialism', 'government_support_agency', 'bureau_of_domestic_affairs', 'pirate_economy',
        'advanced_pirate_economy', 'num_cities', 'highest_city_infra', 'highest_city_population',
        'avg_infra', 'daily_output', 'daily_expenses', 'daily_net', 'production_processes',
        'economy_unavailable',
    ];

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function compact(array $payload): array
    {
        $cities = array_values(array_filter((array) ($payload['cities'] ?? []), 'is_array'));
        if ($cities !== []) {
            $infrastructure = array_values(array_filter(array_map(
                static fn (array $city): mixed => $city['infrastructure'] ?? $city['infra'] ?? null,
                $cities,
            ), static fn (mixed $value): bool => is_numeric($value) && ! is_bool($value)));
            $population = array_values(array_filter(array_map(
                static fn (array $city): mixed => $city['population'] ?? null,
                $cities,
            ), static fn (mixed $value): bool => is_numeric($value) && ! is_bool($value)));

            $payload['num_cities'] = $payload['num_cities'] ?? count($cities);
            if ($infrastructure !== []) {
                $payload['highest_city_infra'] = max($infrastructure);
                $payload['avg_infra'] = array_sum($infrastructure) / count($infrastructure);
            }
            if ($population !== []) {
                $payload['highest_city_population'] = max($population);
            }
        }

        $compact = Arr::only($payload, self::STORED_FIELDS);
        if (isset($compact['alliance']) && is_array($compact['alliance'])) {
            $compact['alliance'] = Arr::only($compact['alliance'], ['id', 'name', 'score']);
            $compact['alliance_score'] = $compact['alliance']['score'] ?? null;
        }

        foreach (['active_wars', 'bounties', 'production_processes', 'projects'] as $key) {
            if (isset($compact[$key]) && is_array($compact[$key])) {
                $compact[$key] = $this->sortList($compact[$key]);
            }
        }

        return $compact;
    }

    /** @param array<string, mixed> $payload @param list<int> $provenanceWarIds */
    public function hash(array $payload, array $provenanceWarIds = []): string
    {
        $state = Arr::except($this->compact($payload), ['nation_name', 'leader_name']);
        if (isset($state['alliance']) && is_array($state['alliance'])) {
            unset($state['alliance']['name']);
        }
        $state['provenance_war_ids'] = array_values(array_unique(array_map('intval', $provenanceWarIds)));
        sort($state['provenance_war_ids'], SORT_NUMERIC);

        return hash('sha256', json_encode(
            $this->canonicalize($state),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /** @param array<mixed> $values @return list<mixed> */
    private function sortList(array $values): array
    {
        $values = array_values(array_map(fn (mixed $value): mixed => $this->canonicalize($value), $values));
        usort($values, static fn (mixed $left, mixed $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            json_encode($right, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ));

        return $values;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}

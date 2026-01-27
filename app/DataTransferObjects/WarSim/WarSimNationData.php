<?php

namespace App\DataTransferObjects\WarSim;

final class WarSimNationData
{
    public function __construct(
        public ?int $nationId,
        public int $soldiers,
        public int $tanks,
        public int $aircraft,
        public int $ships,
        public string $warPolicy,
        public bool $isFortified,
        public ?float $money,
        public int $cities,
        public float $highestCityInfra,
        public int $highestCityPopulation,
        public ?float $avgInfra,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            nationId: isset($payload['nation_id']) ? (int) $payload['nation_id'] : null,
            soldiers: (int) ($payload['soldiers'] ?? 0),
            tanks: (int) ($payload['tanks'] ?? 0),
            aircraft: (int) ($payload['aircraft'] ?? 0),
            ships: (int) ($payload['ships'] ?? 0),
            warPolicy: (string) ($payload['war_policy'] ?? 'NONE'),
            isFortified: (bool) ($payload['is_fortified'] ?? false),
            money: isset($payload['money']) ? (float) $payload['money'] : null,
            cities: (int) ($payload['cities'] ?? 0),
            highestCityInfra: (float) ($payload['highest_city_infra'] ?? 0.0),
            highestCityPopulation: (int) ($payload['highest_city_population'] ?? 0),
            avgInfra: isset($payload['avg_infra']) ? (float) $payload['avg_infra'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nation_id' => $this->nationId,
            'soldiers' => $this->soldiers,
            'tanks' => $this->tanks,
            'aircraft' => $this->aircraft,
            'ships' => $this->ships,
            'war_policy' => $this->warPolicy,
            'is_fortified' => $this->isFortified,
            'money' => $this->money,
            'cities' => $this->cities,
            'highest_city_infra' => $this->highestCityInfra,
            'highest_city_population' => $this->highestCityPopulation,
            'avg_infra' => $this->avgInfra,
        ];
    }
}

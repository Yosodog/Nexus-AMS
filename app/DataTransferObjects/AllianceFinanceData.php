<?php

namespace App\DataTransferObjects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class AllianceFinanceData
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $direction,
        public string $category,
        public ?string $description,
        public CarbonInterface $date,
        public ?int $nationId = null,
        public ?int $accountId = null,
        public ?string $sourceType = null,
        public int|string|null $sourceId = null,
        public ?Model $source = null,
        public float $money = 0.0,
        public float $coal = 0.0,
        public float $oil = 0.0,
        public float $uranium = 0.0,
        public float $iron = 0.0,
        public float $bauxite = 0.0,
        public float $lead = 0.0,
        public float $gasoline = 0.0,
        public float $munitions = 0.0,
        public float $steel = 0.0,
        public float $aluminum = 0.0,
        public float $food = 0.0,
        public array $meta = [],
    ) {
        if ($this->source && ! $this->sourceType) {
            $this->sourceType = $this->source::class;
        }

        if ($this->source && ! $this->sourceId) {
            $this->sourceId = $this->source->getKey();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'category' => $this->category,
            'description' => $this->description,
            'date' => $this->date->toDateString(),
            'nation_id' => $this->nationId,
            'account_id' => $this->accountId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'money' => $this->money,
            'coal' => $this->coal,
            'oil' => $this->oil,
            'uranium' => $this->uranium,
            'iron' => $this->iron,
            'bauxite' => $this->bauxite,
            'lead' => $this->lead,
            'gasoline' => $this->gasoline,
            'munitions' => $this->munitions,
            'steel' => $this->steel,
            'aluminum' => $this->aluminum,
            'food' => $this->food,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            direction: (string) ($payload['direction'] ?? ''),
            category: (string) ($payload['category'] ?? ''),
            description: $payload['description'] ?? null,
            date: Carbon::parse($payload['date']),
            nationId: isset($payload['nation_id']) ? (int) $payload['nation_id'] : null,
            accountId: isset($payload['account_id']) ? (int) $payload['account_id'] : null,
            sourceType: $payload['source_type'] ?? null,
            sourceId: $payload['source_id'] ?? null,
            source: null,
            money: (float) ($payload['money'] ?? 0.0),
            coal: (float) ($payload['coal'] ?? 0.0),
            oil: (float) ($payload['oil'] ?? 0.0),
            uranium: (float) ($payload['uranium'] ?? 0.0),
            iron: (float) ($payload['iron'] ?? 0.0),
            bauxite: (float) ($payload['bauxite'] ?? 0.0),
            lead: (float) ($payload['lead'] ?? 0.0),
            gasoline: (float) ($payload['gasoline'] ?? 0.0),
            munitions: (float) ($payload['munitions'] ?? 0.0),
            steel: (float) ($payload['steel'] ?? 0.0),
            aluminum: (float) ($payload['aluminum'] ?? 0.0),
            food: (float) ($payload['food'] ?? 0.0),
            meta: $payload['meta'] ?? [],
        );
    }

    /**
     * Get the resolved source class name.
     */
    public function sourceType(): ?string
    {
        return $this->sourceType;
    }

    /**
     * Get the resolved source identifier.
     */
    public function sourceId(): int|string|null
    {
        return $this->sourceId;
    }
}

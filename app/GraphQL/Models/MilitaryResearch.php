<?php

namespace App\GraphQL\Models;

use stdClass;

final class MilitaryResearch
{
    private const FIELDS = [
        'ground_capacity',
        'ground_cost',
        'air_capacity',
        'air_cost',
        'naval_capacity',
        'naval_cost',
    ];

    /** @var array<string, true> */
    private array $sourceFields = [];

    public ?int $ground_capacity = null;

    public ?int $ground_cost = null;

    public ?int $air_capacity = null;

    public ?int $air_cost = null;

    public ?int $naval_capacity = null;

    public ?int $naval_cost = null;

    public function buildWithJSON(stdClass $json): void
    {
        $source = get_object_vars($json);
        $this->sourceFields = array_fill_keys(array_intersect(array_keys($source), self::FIELDS), true);

        foreach (array_keys($this->sourceFields) as $field) {
            $this->{$field} = isset($json->{$field})
                ? max(0, min(20, (int) $json->{$field}))
                : null;
        }
    }

    public function hasSourceField(string $field): bool
    {
        return isset($this->sourceFields[$field]);
    }
}

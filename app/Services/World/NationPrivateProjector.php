<?php

declare(strict_types=1);

namespace App\Services\World;

use App\GraphQL\Models\Nation as GraphQLNation;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationResources;

/**
 * Projects the P&W fields that remain tenant-private without touching the
 * shared public-world nation view.
 *
 * Hosted tenants receive the public nation projection from Cloud. Resource
 * and military snapshots are still local tenant data because they power
 * private workflows such as readiness and withdrawals.
 */
final class NationPrivateProjector
{
    /** @var array<string, int> */
    private const DEFAULT_RESOURCES = [
        'money' => 0,
        'coal' => 0,
        'oil' => 0,
        'uranium' => 0,
        'iron' => 0,
        'bauxite' => 0,
        'lead' => 0,
        'gasoline' => 0,
        'munitions' => 0,
        'steel' => 0,
        'aluminum' => 0,
        'food' => 0,
        'credits' => 0,
    ];

    /** @var list<string> */
    private const RESOURCE_FIELDS = [
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
        'credits',
    ];

    /** @var list<string> */
    private const MILITARY_FIELDS = [
        'soldiers',
        'tanks',
        'aircraft',
        'ships',
        'missiles',
        'nukes',
        'spies',
        'soldiers_today',
        'tanks_today',
        'aircraft_today',
        'ships_today',
        'missiles_today',
        'nukes_today',
        'spies_today',
        'soldier_casualties',
        'soldier_kills',
        'tank_casualties',
        'tank_kills',
        'aircraft_casualties',
        'aircraft_kills',
        'ship_casualties',
        'ship_kills',
        'missile_casualties',
        'missile_kills',
        'nuke_casualties',
        'nuke_kills',
        'spy_casualties',
        'spy_kills',
        'spy_attacks',
    ];

    /**
     * @return list<string>
     */
    public static function resourceFields(): array
    {
        return self::RESOURCE_FIELDS;
    }

    /**
     * @return list<string>
     */
    public static function militaryFields(): array
    {
        return self::MILITARY_FIELDS;
    }

    /**
     * Project tenant-private nation state and return the corresponding public
     * nation row when it exists in the hosted view.
     */
    public function project(GraphQLNation $source): ?Nation
    {
        $nationId = $source->id;

        if (! is_int($nationId) || $nationId < 1) {
            return null;
        }

        $nation = Nation::query()->find($nationId);

        if ($nation === null) {
            return null;
        }

        $resourcePayload = $this->presentPayload($source, self::RESOURCE_FIELDS);

        if ($resourcePayload !== []) {
            $this->upsertResources($nationId, $resourcePayload);
        }

        $militaryPayload = $this->presentPayload($source, self::MILITARY_FIELDS);

        if ($militaryPayload !== []) {
            $this->upsertMilitary($nationId, $militaryPayload);
        }

        return $nation;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function presentPayload(GraphQLNation $source, array $fields): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            if ($source->hasSourceField($field) && $source->{$field} !== null) {
                $attributes[$field] = $source->{$field};
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertResources(int $nationId, array $payload): void
    {
        $resources = NationResources::withTrashed()->firstOrNew(['nation_id' => $nationId]);

        if (! $resources->exists) {
            $resources->forceFill(self::DEFAULT_RESOURCES);
        }

        if ($resources->trashed()) {
            $resources->restore();
        }

        $resources->fill($payload)->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertMilitary(int $nationId, array $payload): void
    {
        $military = NationMilitary::withTrashed()->firstOrNew(['nation_id' => $nationId]);

        if (! $military->exists) {
            $military->forceFill(NationMilitary::DEFAULT_COUNTERS);
        } elseif ($military->trashed()) {
            $military->restore();
        }

        $military->fill($payload)->save();
    }
}

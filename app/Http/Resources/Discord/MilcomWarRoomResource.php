<?php

namespace App\Http\Resources\Discord;

use App\Models\MilcomAssignment;
use App\Models\MilcomObjective;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MilcomWarRoomResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MilcomObjective $objective */
        $objective = $this->resource;
        $operation = $objective->operation;
        $target = $objective->target;

        return [
            'objective_id' => (int) $objective->id,
            'discord_channel_id' => (string) $objective->discord_channel_id,
            'status' => $objective->status->value,
            'priority' => $objective->priority_tier->value,
            'war_type' => (string) $objective->war_type,
            'reason' => filled($objective->war_reason) ? (string) $objective->war_reason : null,
            'deadline_at' => $objective->deadline_at?->toIso8601String(),
            'operation' => [
                'id' => (int) $operation->id,
                'name' => (string) $operation->name,
                'type' => $operation->type->value,
                'status' => $operation->status->value,
                'wave' => (int) data_get($operation->metadata, 'wave', 1),
            ],
            'target' => [
                'id' => (int) $target->id,
                'nation_name' => (string) $target->nation_name,
                'leader_name' => (string) $target->leader_name,
                'score' => (float) $target->score,
                'cities' => (int) $target->num_cities,
                'alliance' => $target->alliance !== null ? [
                    'id' => (int) $target->alliance->id,
                    'name' => (string) $target->alliance->name,
                    'acronym' => filled($target->alliance->acronym)
                        ? (string) $target->alliance->acronym
                        : null,
                ] : null,
            ],
            'assigned_members' => $objective->assignments
                ->map(fn (MilcomAssignment $assignment): array => [
                    'assignment_id' => (int) $assignment->id,
                    'rank' => (int) $assignment->rank,
                    'status' => $assignment->status->value,
                    'nation' => [
                        'id' => (int) $assignment->friendlyNation->id,
                        'nation_name' => (string) $assignment->friendlyNation->nation_name,
                        'leader_name' => (string) $assignment->friendlyNation->leader_name,
                    ],
                    'war' => $assignment->declaredWar !== null ? [
                        'id' => (int) $assignment->declaredWar->id,
                        'declared_at' => filled($assignment->declaredWar->date)
                            ? CarbonImmutable::parse((string) $assignment->declaredWar->date)->toIso8601String()
                            : null,
                        'ended_at' => filled($assignment->declaredWar->end_date)
                            ? CarbonImmutable::parse((string) $assignment->declaredWar->end_date)->toIso8601String()
                            : null,
                        'turns_left' => (int) $assignment->declaredWar->turns_left,
                        'friendly_resistance' => (int) $assignment->declaredWar->att_resistance,
                        'target_resistance' => (int) $assignment->declaredWar->def_resistance,
                        'winner_nation_id' => $assignment->declaredWar->winner_id !== null
                            ? (int) $assignment->declaredWar->winner_id
                            : null,
                    ] : null,
                ])
                ->values()
                ->all(),
            'links' => [
                'target_nation' => "https://politicsandwar.com/nation/id={$target->id}",
                'declare_war' => "https://politicsandwar.com/nation/war/declare/id={$target->id}",
            ],
        ];
    }
}

<?php

namespace App\Http\Resources\Discord;

use App\Models\MilcomAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MilcomAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MilcomAssignment $assignment */
        $assignment = $this->resource;
        $objective = $assignment->objective;
        $operation = $objective->operation;
        $target = $objective->target;
        $war = $assignment->declaredWar;

        return [
            'assignment_id' => (int) $assignment->id,
            'status' => $assignment->status->value,
            'rank' => (int) $assignment->rank,
            'operation' => [
                'id' => (int) $operation->id,
                'name' => (string) $operation->name,
                'type' => $operation->type->value,
                'status' => $operation->status->value,
                'wave' => (int) data_get($operation->metadata, 'wave', 1),
            ],
            'objective' => [
                'id' => (int) $objective->id,
                'status' => $objective->status->value,
                'priority' => $objective->priority_tier->value,
                'war_type' => (string) $objective->war_type,
                'reason' => filled($objective->war_reason) ? (string) $objective->war_reason : null,
                'deadline_at' => $objective->deadline_at?->toIso8601String(),
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
            'war' => $war !== null ? [
                'id' => (int) $war->id,
                'declared_at' => filled($war->date)
                    ? CarbonImmutable::parse((string) $war->date)->toIso8601String()
                    : null,
                'ended_at' => filled($war->end_date)
                    ? CarbonImmutable::parse((string) $war->end_date)->toIso8601String()
                    : null,
                'turns_left' => (int) $war->turns_left,
                'friendly_resistance' => (int) $war->att_resistance,
                'target_resistance' => (int) $war->def_resistance,
                'winner_nation_id' => $war->winner_id !== null ? (int) $war->winner_id : null,
            ] : null,
            'room' => [
                'available' => filled($objective->discord_channel_id),
                'discord_channel_id' => filled($objective->discord_channel_id)
                    ? (string) $objective->discord_channel_id
                    : null,
            ],
            'links' => [
                'target_nation' => "https://politicsandwar.com/nation/id={$target->id}",
                'declare_war' => "https://politicsandwar.com/nation/war/declare/id={$target->id}",
                'war_timeline' => $war !== null
                    ? "https://politicsandwar.com/nation/war/timeline/war={$war->id}"
                    : null,
            ],
        ];
    }
}

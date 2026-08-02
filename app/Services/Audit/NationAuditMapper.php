<?php

namespace App\Services\Audit;

use App\Models\Nation;
use App\Services\PWHelperService;

final class NationAuditMapper
{
    /**
     * Build the flat, typed context used by the guided audit evaluator.
     *
     * @return array<string, mixed>
     */
    public function buildContext(Nation $nation): array
    {
        $resources = $nation->relationLoaded('resources') ? $nation->resources : null;
        $military = $nation->relationLoaded('military') ? $nation->military : null;
        $account = $nation->relationLoaded('accountProfile') ? $nation->accountProfile : null;
        $latestSignIn = $nation->relationLoaded('latestSignIn') ? $nation->latestSignIn : null;
        $cityCount = $nation->num_cities !== null ? (int) $nation->num_cities : null;
        $context = [
            'nation.id' => $nation->id,
            'nation.alliance_id' => $nation->alliance_id,
            'nation.alliance_position' => $nation->alliance_position,
            'nation.nation_name' => $nation->nation_name,
            'nation.leader_name' => $nation->leader_name,
            'nation.continent' => $nation->continent,
            'nation.war_policy' => $nation->war_policy,
            'nation.domestic_policy' => $nation->domestic_policy,
            'nation.color' => $nation->color,
            'nation.num_cities' => $cityCount,
            'nation.score' => $nation->score,
            'nation.population' => $nation->population,
            'nation.projects_count' => $nation->getRawOriginal('projects'),
            'nation.alliance_seniority' => $nation->alliance_seniority,
            'nation.beige_turns' => $nation->beige_turns,
            'nation.vacation_mode_turns' => $nation->vacation_mode_turns,
            'nation.turns_since_last_city' => $nation->turns_since_last_city,
            'nation.turns_since_last_project' => $nation->turns_since_last_project,
            'nation.wars_won' => $nation->wars_won,
            'nation.wars_lost' => $nation->wars_lost,
            'nation.offensive_wars_count' => $nation->offensive_wars_count,
            'nation.defensive_wars_count' => $nation->defensive_wars_count,
            'nation.gni' => $nation->gross_national_income,
            'nation.gdp' => $nation->gross_domestic_product,
            'nation.commendations' => $nation->commendations,
            'nation.denouncements' => $nation->denouncements,
            'nation.account_credits' => $account?->credits,
            'nation.last_activity' => $account?->last_active?->toIso8601String(),
            'nation.days_since_last_activity' => $account?->last_active?->diffInDays(now()),
            'nation.discord_account_linked' => $account === null ? null : $this->hasValue($account->discord_id),
            'nation.mmr_score' => $latestSignIn?->mmr_score,
            'nation.projects' => $nation->project_bits === null
                ? null
                : PWHelperService::getNationProjects($nation->project_bits),
        ];

        foreach (PWHelperService::resources(true, true) as $resource) {
            $context["nation.{$resource}"] = $resources?->{$resource};
        }

        foreach (['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'] as $unit) {
            $value = $military?->{$unit};
            $context["nation.{$unit}"] = $value;
            $context["nation.{$unit}_per_city"] = $value !== null && $cityCount !== null && $cityCount > 0
                ? round((float) $value / $cityCount, 2)
                : null;
        }

        return $context;
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}

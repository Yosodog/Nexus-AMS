<?php

namespace App\Services\Audit;

use App\Models\Nation;

class NationAuditMapper
{
    /**
     * Build the flat nation.* variable map expected by NEL.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildVariables(Nation $nation): array
    {
        $resources = $nation->resources;
        $military = $nation->military;
        $account = $nation->accountProfile;
        $latestSignIn = $nation->latestSignIn;

        return [
            'nation' => [
                'id' => $nation->id,
                'alliance_id' => $nation->alliance_id,
                'alliance_position' => $nation->alliance_position,
                'nation_name' => $nation->nation_name,
                'leader_name' => $nation->leader_name,
                'continent' => $nation->continent,
                'war_policy' => $nation->war_policy,
                'domestic_policy' => $nation->domestic_policy,
                'color' => $nation->color,
                'num_cities' => $nation->num_cities,
                'score' => $nation->score,
                'population' => $nation->population,
                'projects_count' => $nation->projects,
                'project_bits' => $nation->project_bits,
                'wars_won' => $nation->wars_won,
                'wars_lost' => $nation->wars_lost,
                'offensive_wars_count' => $nation->offensive_wars_count,
                'defensive_wars_count' => $nation->defensive_wars_count,
                'gni' => $nation->gross_national_income,
                'gdp' => $nation->gross_domestic_product,
                'commendations' => $nation->commendations,
                'denouncements' => $nation->denouncements,

                // Resources
                'money' => $resources?->money,
                'coal' => $resources?->coal,
                'oil' => $resources?->oil,
                'uranium' => $resources?->uranium,
                'iron' => $resources?->iron,
                'bauxite' => $resources?->bauxite,
                'lead' => $resources?->lead,
                'gasoline' => $resources?->gasoline,
                'munitions' => $resources?->munitions,
                'steel' => $resources?->steel,
                'aluminum' => $resources?->aluminum,
                'food' => $resources?->food,
                'credits' => $resources?->credits,

                // Military
                'soldiers' => $military?->soldiers,
                'tanks' => $military?->tanks,
                'aircraft' => $military?->aircraft,
                'ships' => $military?->ships,
                'missiles' => $military?->missiles,
                'nukes' => $military?->nukes,
                'spies' => $military?->spies,

                // Account
                'account_credits' => $account?->credits,
                'last_active' => $account?->last_active?->timestamp,
                'account_discord_id' => $account?->discord_id,

                // Latest sign in snapshot
                'mmr_score' => $latestSignIn?->mmr_score,
            ],
        ];
    }
}

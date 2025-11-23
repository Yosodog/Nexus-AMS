<?php

namespace App\Nel\Profiles;

use App\Models\Nation;

final class NationNelProfile
{
    /**
     * @return array<string, mixed>
     */
    public function buildVariables(Nation $nation): array
    {
        $military = $nation->military;

        return [
            'nation' => [
                'id' => $nation->id,
                'name' => $nation->nation_name ?? $nation->name ?? null,
                'score' => $nation->score,
                'military' => [
                    'soldiers' => $military?->soldiers,
                    'tanks' => $military?->tanks,
                    'aircraft' => $military?->aircraft,
                    'ships' => $military?->ships,
                    'spies' => $military?->spies,
                ],
            ],
        ];
    }
}

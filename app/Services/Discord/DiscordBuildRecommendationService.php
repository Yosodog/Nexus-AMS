<?php

namespace App\Services\Discord;

use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Models\User;
use App\Services\Economy\EconomyRules;
use App\Services\NationBuildRecommendationService;
use Illuminate\Support\Str;

final readonly class DiscordBuildRecommendationService
{
    public function __construct(private NationBuildRecommendationService $recommendations) {}

    /** @return array<string, mixed> */
    public function forActor(User $actor): array
    {
        $nation = Nation::query()
            ->with('buildRecommendation')
            ->find((int) $actor->nation_id);

        abort_unless($nation instanceof Nation, 404, 'The linked nation could not be found.');

        $recommendation = $nation->buildRecommendation;
        $base = [
            'contract_version' => 1,
            'nation' => [
                'id' => (int) $nation->id,
                'name' => (string) $nation->nation_name,
            ],
            'deep_link_path' => route('audit.index', absolute: false),
        ];

        if (! $recommendation instanceof NationBuildRecommendation
            || $recommendation->model_version !== EconomyRules::MODEL_VERSION) {
            return [
                ...$base,
                'state' => 'unavailable',
                'message' => 'No current build recommendation is available. Open Audit Center to generate one.',
            ];
        }

        $groups = collect($this->recommendations->buildDisplayGroups(
            $recommendation->recommended_build_json ?? []
        ))
            ->filter(fn (array $items): bool => $items !== [])
            ->map(fn (array $items, string $key): array => [
                'key' => $key,
                'label' => Str::headline($key),
                'items' => $items,
            ])
            ->values()
            ->all();

        return [
            ...$base,
            'state' => 'ready',
            'message' => 'Current recommended city build from Nexus.',
            'recommendation' => [
                'target_infrastructure' => (int) $recommendation->infra_needed,
                'land_used' => (float) $recommendation->land_used,
                'used_slots' => (int) $recommendation->imp_total,
                'available_slots' => (int) $recommendation->available_slots,
                'cities_below_target' => (int) $recommendation->cities_below_target,
                'infrastructure_shortfall' => (float) $recommendation->infrastructure_shortfall,
                'converted_profit_per_day' => (float) $recommendation->converted_profit_per_day,
                'market_stale' => (bool) data_get($recommendation->calculation_context, 'market.stale', false),
                'groups' => $groups,
                'calculated_at' => $recommendation->calculated_at?->toIso8601String(),
            ],
        ];
    }
}

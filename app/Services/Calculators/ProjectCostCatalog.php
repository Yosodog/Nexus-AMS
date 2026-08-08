<?php

namespace App\Services\Calculators;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class ProjectCostCatalog
{
    public const SOURCE_COMMIT = '41c9fd07e38c4afd135ab9d84ba1ccaae1d2fbe8';

    public const SOURCE_URL = 'https://github.com/xdnw/locutus/blob/41c9fd07e38c4afd135ab9d84ba1ccaae1d2fbe8/src/main/java/link/locutus/discord/apiv1/enums/city/project/Projects.java';

    /**
     * Removed planning projects are intentionally excluded because P&W disabled them when the dynamic city formula launched.
     *
     * @var array<string, array<string, float|int>>
     */
    private const COSTS = [
        'activity_center' => ['money' => 500_000, 'food' => 1_000],
        'advanced_engineering_corps' => ['money' => 50_000_000, 'uranium' => 1_000, 'munitions' => 10_000, 'gasoline' => 10_000],
        'advanced_pirate_economy' => ['money' => 50_000_000, 'coal' => 10_000, 'iron' => 10_000, 'oil' => 10_000, 'bauxite' => 10_000, 'lead' => 10_000],
        'arable_land_agency' => ['money' => 3_000_000, 'coal' => 1_500, 'lead' => 1_500],
        'arms_stockpile' => ['money' => 10_000_000, 'coal' => 500, 'iron' => 500, 'oil' => 500, 'bauxite' => 500, 'lead' => 500],
        'bauxite_works' => ['money' => 10_000_000, 'coal' => 500, 'iron' => 500, 'oil' => 500, 'bauxite' => 500, 'lead' => 500],
        'bureau_of_domestic_affairs' => ['money' => 20_000_000, 'food' => 500_000, 'coal' => 8_000, 'bauxite' => 8_000, 'lead' => 8_000, 'iron' => 8_000, 'oil' => 8_000],
        'center_for_civil_engineering' => ['money' => 3_000_000, 'oil' => 1_000, 'iron' => 1_000, 'bauxite' => 1_000],
        'central_intelligence_agency' => ['money' => 5_000_000, 'steel' => 500, 'gasoline' => 500],
        'clinical_research_center' => ['money' => 10_000_000, 'food' => 100_000],
        'emergency_gasoline_reserve' => ['money' => 10_000_000, 'coal' => 500, 'iron' => 500, 'oil' => 500, 'bauxite' => 500, 'lead' => 500],
        'fallout_shelter' => ['money' => 25_000_000, 'food' => 100_000, 'lead' => 10_000, 'aluminum' => 15_000, 'steel' => 10_000],
        'government_support_agency' => ['money' => 20_000_000, 'food' => 200_000, 'aluminum' => 10_000],
        'green_tech' => ['money' => 50_000_000, 'food' => 100_000, 'aluminum' => 10_000, 'iron' => 10_000, 'oil' => 10_000],
        'guiding_satellite' => ['money' => 200_000_000, 'munitions' => 40_000, 'uranium' => 40_000, 'gasoline' => 40_000, 'aluminum' => 40_000, 'steel' => 20_000],
        'international_trade_center' => ['money' => 50_000_000, 'aluminum' => 10_000],
        'iron_dome' => ['money' => 15_000_000, 'munitions' => 5_000],
        'iron_works' => ['money' => 10_000_000, 'coal' => 500, 'iron' => 500, 'oil' => 500, 'bauxite' => 500, 'lead' => 500],
        'mars_landing' => ['money' => 200_000_000, 'oil' => 20_000, 'aluminum' => 20_000, 'munitions' => 20_000, 'steel' => 20_000, 'gasoline' => 20_000, 'uranium' => 20_000],
        'mass_irrigation' => ['money' => 10_000_000, 'food' => 50_000, 'coal' => 500, 'iron' => 500, 'oil' => 500, 'bauxite' => 500, 'lead' => 500],
        'military_doctrine' => ['money' => 10_000_000, 'steel' => 10_000, 'aluminum' => 10_000, 'munitions' => 10_000, 'gasoline' => 10_000],
        'military_research_center' => ['money' => 100_000_000, 'steel' => 10_000, 'aluminum' => 10_000, 'munitions' => 10_000, 'gasoline' => 10_000],
        'military_salvage' => ['money' => 20_000_000, 'aluminum' => 5_000, 'steel' => 5_000, 'gasoline' => 5_000],
        'missile_launch_pad' => ['money' => 15_000_000, 'munitions' => 5_000, 'gasoline' => 5_000, 'aluminum' => 5_000],
        'moon_landing' => ['money' => 50_000_000, 'oil' => 5_000, 'munitions' => 5_000, 'gasoline' => 5_000, 'steel' => 5_000, 'aluminum' => 5_000, 'uranium' => 10_000],
        'nuclear_launch_facility' => ['money' => 750_000_000, 'uranium' => 50_000, 'gasoline' => 50_000, 'aluminum' => 50_000],
        'nuclear_research_facility' => ['money' => 75_000_000, 'uranium' => 5_000, 'gasoline' => 5_000, 'aluminum' => 5_000],
        'pirate_economy' => ['money' => 25_000_000, 'coal' => 7_500, 'iron' => 7_500, 'oil' => 7_500, 'bauxite' => 7_500, 'lead' => 7_500],
        'propaganda_bureau' => ['money' => 10_000_000, 'gasoline' => 2_000, 'munitions' => 2_000, 'aluminum' => 2_000, 'steel' => 2_000],
        'recycling_initiative' => ['money' => 10_000_000, 'food' => 100_000],
        'research_and_development_center' => ['money' => 50_000_000, 'uranium' => 1_000, 'aluminum' => 5_000, 'food' => 100_000],
        'space_program' => ['money' => 50_000_000, 'aluminum' => 25_000],
        'specialized_police_training_program' => ['money' => 50_000_000, 'food' => 250_000, 'aluminum' => 5_000],
        'spy_satellite' => ['money' => 20_000_000, 'oil' => 10_000, 'bauxite' => 10_000, 'iron' => 10_000, 'lead' => 10_000, 'coal' => 10_000],
        'surveillance_network' => ['money' => 50_000_000, 'aluminum' => 50_000, 'bauxite' => 15_000, 'iron' => 15_000, 'lead' => 15_000, 'coal' => 15_000],
        'telecommunications_satellite' => ['money' => 300_000_000, 'uranium' => 10_000, 'iron' => 10_000, 'oil' => 10_000, 'aluminum' => 10_000],
        'uranium_enrichment_program' => ['money' => 25_000_000, 'uranium' => 2_500, 'coal' => 500, 'iron' => 500, 'oil' => 500, 'bauxite' => 500, 'lead' => 500],
        'vital_defense_system' => ['money' => 40_000_000, 'steel' => 5_000, 'aluminum' => 5_000, 'munitions' => 5_000, 'gasoline' => 5_000],
    ];

    /**
     * @return array<string, array{label: string, costs: array<string, float|int>}>
     */
    public function all(): array
    {
        return collect(self::COSTS)
            ->mapWithKeys(fn (array $costs, string $key): array => [$key => [
                'label' => Str::of($key === 'green_tech' ? 'green technologies' : $key)->headline()->toString(),
                'costs' => $costs,
            ]])
            ->sortBy('label')
            ->all();
    }

    /**
     * @return array{label: string, costs: array<string, float|int>}
     */
    public function get(string $project): array
    {
        $definition = $this->all()[$project] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException('Select a supported national project.');
        }

        return $definition;
    }
}

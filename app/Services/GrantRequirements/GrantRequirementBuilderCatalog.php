<?php

namespace App\Services\GrantRequirements;

use App\Services\PWHelperService;
use Illuminate\Support\Arr;

class GrantRequirementBuilderCatalog
{
    /**
     * @param  array<string, mixed>|null  $defaultTree
     * @return array<string, mixed>
     */
    public function getBuilderConfig(?array $defaultTree): array
    {
        return [
            'groups' => [
                ['value' => 'all', 'label' => 'All conditions must match'],
                ['value' => 'any', 'label' => 'Any condition may match'],
                ['value' => 'not', 'label' => 'None of these may match'],
            ],
            'operators' => $this->operators(),
            'fields' => array_values($this->fields()),
            'default_tree' => $defaultTree,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        static $catalog = null;

        if ($catalog !== null) {
            return $catalog;
        }

        $enumOptions = fn (array $values): array => collect($values)
            ->map(fn (string $value): array => ['value' => $value, 'label' => $this->humanizeOption($value)])
            ->values()
            ->all();

        $numberFields = [
            'num_cities' => ['label' => 'City count', 'category' => 'Nation'],
            'score' => ['label' => 'Score', 'category' => 'Nation'],
            'mmr_score' => ['label' => 'MMR score', 'category' => 'Nation'],
            'alliance_seniority' => ['label' => 'Alliance seniority', 'category' => 'Nation'],
            'beige_turns' => ['label' => 'Beige turns', 'category' => 'Nation'],
            'vacation_mode_turns' => ['label' => 'Vacation mode turns', 'category' => 'Nation'],
            'turns_since_last_city' => ['label' => 'Turns since last city', 'category' => 'Nation'],
            'turns_since_last_project' => ['label' => 'Turns since last project', 'category' => 'Nation'],
            'wars_won' => ['label' => 'Wars won', 'category' => 'Nation'],
            'wars_lost' => ['label' => 'Wars lost', 'category' => 'Nation'],
            'offensive_wars_count' => ['label' => 'Offensive wars', 'category' => 'Nation'],
            'defensive_wars_count' => ['label' => 'Defensive wars', 'category' => 'Nation'],
            'population' => ['label' => 'Population', 'category' => 'Nation'],
            'gross_national_income' => ['label' => 'Gross national income', 'category' => 'Nation'],
            'gross_domestic_product' => ['label' => 'Gross domestic product', 'category' => 'Nation'],
            'total_infrastructure' => ['label' => 'Total infrastructure', 'category' => 'Cities'],
            'avg_infrastructure_per_city' => ['label' => 'Average infrastructure per city', 'category' => 'Cities'],
            'soldiers' => ['label' => 'Soldiers', 'category' => 'Military'],
            'tanks' => ['label' => 'Tanks', 'category' => 'Military'],
            'aircraft' => ['label' => 'Aircraft', 'category' => 'Military'],
            'ships' => ['label' => 'Ships', 'category' => 'Military'],
            'missiles' => ['label' => 'Missiles', 'category' => 'Military'],
            'nukes' => ['label' => 'Nukes', 'category' => 'Military'],
            'spies' => ['label' => 'Spies', 'category' => 'Military'],
            'soldiers_per_city' => ['label' => 'Soldiers per city', 'category' => 'Military'],
            'tanks_per_city' => ['label' => 'Tanks per city', 'category' => 'Military'],
            'aircraft_per_city' => ['label' => 'Aircraft per city', 'category' => 'Military'],
            'ships_per_city' => ['label' => 'Ships per city', 'category' => 'Military'],
            'missiles_per_city' => ['label' => 'Missiles per city', 'category' => 'Military'],
            'nukes_per_city' => ['label' => 'Nukes per city', 'category' => 'Military'],
        ];

        foreach (PWHelperService::resources(true, true) as $resource) {
            $numberFields[$resource] = [
                'label' => ucfirst($resource),
                'category' => 'Resources',
            ];
        }

        $catalog = collect($numberFields)
            ->mapWithKeys(function (array $meta, string $key): array {
                return [
                    $key => [
                        'key' => $key,
                        'label' => $meta['label'],
                        'category' => $meta['category'],
                        'type' => 'number',
                        'operators' => ['gt', 'gte', 'lt', 'lte', 'eq', 'neq', 'between', 'not_between'],
                    ],
                ];
            })
            ->merge([
                'domestic_policy' => [
                    'key' => 'domestic_policy',
                    'label' => 'Domestic policy',
                    'category' => 'Policies',
                    'type' => 'enum',
                    'operators' => ['eq', 'neq', 'in', 'not_in'],
                    'options' => $enumOptions([
                        'MANIFEST_DESTINY',
                        'OPEN_MARKETS',
                        'TECHNOLOGICAL_ADVANCEMENT',
                        'URBANIZATION',
                    ]),
                ],
                'war_policy' => [
                    'key' => 'war_policy',
                    'label' => 'War policy',
                    'category' => 'Policies',
                    'type' => 'enum',
                    'operators' => ['eq', 'neq', 'in', 'not_in'],
                    'options' => $enumOptions([
                        'ATTRITION',
                        'TURTLE',
                        'BLITZKRIEG',
                        'FORTRESS',
                        'MONEYBAGS',
                        'PIRATE',
                        'TACTICIAN',
                        'GUARDIAN',
                        'COVERT',
                        'ARCANE',
                        'NONE',
                    ]),
                ],
                'color' => [
                    'key' => 'color',
                    'label' => 'Color',
                    'category' => 'Policies',
                    'type' => 'enum',
                    'operators' => ['eq', 'neq', 'in', 'not_in'],
                    'options' => $enumOptions([
                        'AQUA',
                        'BEIGE',
                        'BLACK',
                        'BLUE',
                        'BROWN',
                        'GRAY',
                        'GREEN',
                        'LIME',
                        'MAROON',
                        'OLIVE',
                        'ORANGE',
                        'PINK',
                        'PURPLE',
                        'RED',
                        'WHITE',
                        'YELLOW',
                    ]),
                ],
                'continent' => [
                    'key' => 'continent',
                    'label' => 'Continent',
                    'category' => 'Nation',
                    'type' => 'enum',
                    'operators' => ['eq', 'neq', 'in', 'not_in'],
                    'options' => $enumOptions([
                        'AFRICA',
                        'ANTARCTICA',
                        'ASIA',
                        'AUSTRALIA',
                        'EUROPE',
                        'NORTH_AMERICA',
                        'SOUTH_AMERICA',
                    ]),
                ],
                'alliance_position' => [
                    'key' => 'alliance_position',
                    'label' => 'Alliance position',
                    'category' => 'Nation',
                    'type' => 'enum',
                    'operators' => ['eq', 'neq', 'in', 'not_in'],
                    'options' => $enumOptions([
                        'APPLICANT',
                        'MEMBER',
                        'OFFICER',
                        'HEIR',
                        'LEADER',
                    ]),
                ],
                'growth_circle_enrollment' => [
                    'key' => 'growth_circle_enrollment',
                    'label' => 'Growth Circles enrollment',
                    'category' => 'Programs',
                    'type' => 'enum',
                    'operators' => ['eq', 'neq'],
                    'options' => $enumOptions([
                        'ENROLLED',
                        'NOT_ENROLLED',
                    ]),
                ],
                'projects' => [
                    'key' => 'projects',
                    'label' => 'projects',
                    'category' => 'Projects',
                    'type' => 'collection',
                    'operators' => ['contains_all', 'contains_any', 'contains_none'],
                    'options' => collect(PWHelperService::projects())
                        ->map(fn (string $project): array => ['value' => $project, 'label' => $project])
                        ->values()
                        ->all(),
                ],
            ])
            ->sortBy([
                ['category', 'asc'],
                ['label', 'asc'],
            ])
            ->all();

        return $catalog;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function operators(): array
    {
        return [
            ['value' => 'gt', 'label' => 'Greater than', 'value_type' => 'number'],
            ['value' => 'gte', 'label' => 'At least', 'value_type' => 'number'],
            ['value' => 'lt', 'label' => 'Less than', 'value_type' => 'number'],
            ['value' => 'lte', 'label' => 'At most', 'value_type' => 'number'],
            ['value' => 'eq', 'label' => 'Equals', 'value_type' => 'single'],
            ['value' => 'neq', 'label' => 'Does not equal', 'value_type' => 'single'],
            ['value' => 'between', 'label' => 'Between', 'value_type' => 'range'],
            ['value' => 'not_between', 'label' => 'Not between', 'value_type' => 'range'],
            ['value' => 'in', 'label' => 'Is one of', 'value_type' => 'multi'],
            ['value' => 'not_in', 'label' => 'Is not one of', 'value_type' => 'multi'],
            ['value' => 'contains_all', 'label' => 'Contains all', 'value_type' => 'multi'],
            ['value' => 'contains_any', 'label' => 'Contains any', 'value_type' => 'multi'],
            ['value' => 'contains_none', 'label' => 'Contains none', 'value_type' => 'multi'],
        ];
    }

    /**
     * @param  array<int, string>|string  $value
     */
    public function displayList(array|string $value): string
    {
        return collect(Arr::wrap($value))
            ->map(fn (mixed $item): string => $this->displayValue($item))
            ->implode(', ');
    }

    public function displayValue(mixed $value): string
    {
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }

        return $this->humanizeOption((string) $value);
    }

    private function humanizeOption(string $value): string
    {
        if ($value === strtoupper($value)) {
            return ucwords(strtolower(str_replace('_', ' ', $value)));
        }

        return $value;
    }
}

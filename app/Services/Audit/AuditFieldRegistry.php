<?php

namespace App\Services\Audit;

use App\Enums\AuditTargetType;
use App\Services\PWHelperService;

final class AuditFieldRegistry
{
    /**
     * @return array<string, mixed>
     */
    public function builderConfig(AuditTargetType $targetType): array
    {
        return [
            'schema_version' => 1,
            'groups' => [
                ['value' => 'all', 'label' => 'All conditions match'],
                ['value' => 'any', 'label' => 'Any condition matches'],
            ],
            'operators' => $this->operatorCatalog(),
            'fields' => array_values(array_map(
                static fn (array $field): array => array_diff_key($field, array_flip(['dependencies'])),
                $this->forTarget($targetType),
            )),
            'default_definition' => $this->emptyDefinition(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyDefinition(): array
    {
        return [
            'schema_version' => 1,
            'criteria' => [
                'group' => 'all',
                'rules' => [],
            ],
            'exceptions' => [
                'group' => 'any',
                'rules' => [],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function forTarget(AuditTargetType $targetType): array
    {
        static $catalogs = [];

        return $catalogs[$targetType->value] ??= match ($targetType) {
            AuditTargetType::Nation => $this->nationCatalog(),
            AuditTargetType::City => $this->cityCatalog(),
        };
    }

    /**
     * @param  array<int, string>  $fieldKeys
     * @return array{columns: array<int, string>, relations: array<string, array<int, string>>}
     */
    public function dependenciesFor(AuditTargetType $targetType, array $fieldKeys): array
    {
        $catalog = $this->forTarget($targetType);
        $columns = $targetType === AuditTargetType::Nation
            ? ['id', 'alliance_id', 'alliance_position', 'vacation_mode_turns', 'nation_name', 'leader_name']
            : ['id', 'nation_id', 'name'];
        $relations = [];

        foreach ($fieldKeys as $fieldKey) {
            $dependencies = $catalog[$fieldKey]['dependencies'] ?? [];

            foreach ($dependencies as $source => $sourceColumns) {
                if ($source === 'self') {
                    $columns = [...$columns, ...$sourceColumns];

                    continue;
                }

                $relations[$source] = [...($relations[$source] ?? []), ...$sourceColumns];
            }
        }

        $columns = array_values(array_unique($columns));

        foreach ($relations as $relation => $relationColumns) {
            $relations[$relation] = array_values(array_unique($relationColumns));
        }

        return compact('columns', 'relations');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function nationCatalog(): array
    {
        $fields = [
            $this->number('nation.id', 'Nation ID', 'Nation', ['self' => ['id']], 'ID'),
            $this->number('nation.alliance_id', 'Alliance ID', 'Nation', ['self' => ['alliance_id']], 'ID'),
            $this->text('nation.nation_name', 'Nation name', 'Nation', ['self' => ['nation_name']]),
            $this->text('nation.leader_name', 'Leader name', 'Nation', ['self' => ['leader_name']]),
            $this->enum('nation.alliance_position', 'Alliance position', 'Nation', ['self' => ['alliance_position']], [
                'APPLICANT', 'MEMBER', 'OFFICER', 'HEIR', 'LEADER', 'NOALLIANCE',
            ]),
            $this->enum('nation.continent', 'Continent', 'Nation', ['self' => ['continent']], [
                'AF', 'AN', 'AS', 'AU', 'EU', 'NA', 'SA',
                'AFRICA', 'ANTARCTICA', 'ASIA', 'AUSTRALIA', 'EUROPE', 'NORTH_AMERICA', 'SOUTH_AMERICA',
            ]),
            $this->enum('nation.color', 'Color', 'Nation', ['self' => ['color']], [
                'AQUA', 'BEIGE', 'BLACK', 'BLUE', 'BROWN', 'GRAY', 'GREEN', 'LIME', 'MAROON', 'OLIVE',
                'ORANGE', 'PINK', 'PURPLE', 'RED', 'WHITE', 'YELLOW',
            ]),
            $this->number('nation.num_cities', 'City count', 'Nation', ['self' => ['num_cities']], 'cities'),
            $this->number('nation.score', 'Score', 'Nation', ['self' => ['score']], 'score'),
            $this->number('nation.population', 'Population', 'Nation', ['self' => ['population']], 'people'),
            $this->number('nation.projects_count', 'Project count', 'Nation', ['self' => ['projects']], 'projects'),
            $this->number('nation.alliance_seniority', 'Alliance seniority', 'Nation', ['self' => ['alliance_seniority']], 'days'),
            $this->number('nation.beige_turns', 'Beige turns', 'Nation', ['self' => ['beige_turns']], 'turns'),
            $this->number('nation.vacation_mode_turns', 'Vacation mode turns', 'Nation', ['self' => ['vacation_mode_turns']], 'turns'),
            $this->number('nation.turns_since_last_city', 'Turns since last city', 'Nation', ['self' => ['turns_since_last_city']], 'turns'),
            $this->number('nation.turns_since_last_project', 'Turns since last project', 'Nation', ['self' => ['turns_since_last_project']], 'turns'),
            $this->number('nation.commendations', 'Commendations', 'Nation', ['self' => ['commendations']]),
            $this->number('nation.denouncements', 'Denouncements', 'Nation', ['self' => ['denouncements']]),
            $this->enum('nation.war_policy', 'War policy', 'Policies', ['self' => ['war_policy']], [
                'ATTRITION', 'TURTLE', 'BLITZKRIEG', 'FORTRESS', 'MONEYBAGS', 'PIRATE', 'TACTICIAN',
                'GUARDIAN', 'COVERT', 'ARCANE', 'NONE',
            ]),
            $this->enum('nation.domestic_policy', 'Domestic policy', 'Policies', ['self' => ['domestic_policy']], [
                'MANIFEST_DESTINY', 'OPEN_MARKETS', 'TECHNOLOGICAL_ADVANCEMENT', 'URBANIZATION',
            ]),
            $this->number('nation.wars_won', 'Wars won', 'War activity', ['self' => ['wars_won']], 'wars'),
            $this->number('nation.wars_lost', 'Wars lost', 'War activity', ['self' => ['wars_lost']], 'wars'),
            $this->number('nation.offensive_wars_count', 'Current offensive wars', 'War activity', ['self' => ['offensive_wars_count']], 'wars'),
            $this->number('nation.defensive_wars_count', 'Current defensive wars', 'War activity', ['self' => ['defensive_wars_count']], 'wars'),
            $this->number('nation.gni', 'Gross national income', 'Economy', ['self' => ['gross_national_income']], 'currency'),
            $this->number('nation.gdp', 'Gross domestic product', 'Economy', ['self' => ['gross_domestic_product']], 'currency'),
            $this->number('nation.account_credits', 'Account credits', 'Account', ['accountProfile' => ['nation_id', 'credits']], 'credits'),
            $this->number('nation.mmr_score', 'MMR score', 'Military', ['latestSignIn' => ['id', 'nation_id', 'mmr_score']]),
            $this->datetime('nation.last_activity', 'Last activity', 'Account', ['accountProfile' => ['nation_id', 'last_active']]),
            $this->number('nation.days_since_last_activity', 'Days since last activity', 'Account', ['accountProfile' => ['nation_id', 'last_active']], 'days'),
            $this->boolean('nation.discord_account_linked', 'Discord account linked', 'Account', ['accountProfile' => ['nation_id', 'discord_id']]),
            $this->collection('nation.projects', 'Owned projects', 'Projects', ['self' => ['project_bits']], PWHelperService::projects()),
        ];

        foreach (PWHelperService::resources(true, true) as $resource) {
            $fields[] = $this->number(
                "nation.{$resource}",
                $this->humanize($resource),
                'Resources',
                ['resources' => ['nation_id', $resource]],
                $resource === 'money' ? 'currency' : $resource,
            );
        }

        foreach (['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'] as $unit) {
            $fields[] = $this->number(
                "nation.{$unit}",
                $this->humanize($unit),
                'Military',
                ['military' => ['nation_id', $unit]],
                $unit,
            );

            $fields[] = $this->number(
                "nation.{$unit}_per_city",
                $this->humanize($unit).' per city',
                'Military',
                ['self' => ['num_cities'], 'military' => ['nation_id', $unit]],
                $unit.' / city',
            );
        }

        return $this->sortAndKey($fields);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function cityCatalog(): array
    {
        $fields = [
            $this->number('city.id', 'City ID', 'City', ['self' => ['id']], 'ID'),
            $this->text('city.name', 'City name', 'City', ['self' => ['name']]),
            $this->number('city.infrastructure', 'Infrastructure', 'City', ['self' => ['infrastructure']], 'infra'),
            $this->number('city.land', 'Land', 'City', ['self' => ['land']], 'land'),
            $this->boolean('city.powered', 'City powered', 'City', ['self' => ['powered']]),
            $this->number('city.improvement_count', 'Improvement count', 'City capacity', ['self' => $this->cityImprovementColumns()], 'improvements'),
            $this->number('city.improvement_capacity', 'Improvement capacity', 'City capacity', ['self' => ['infrastructure']], 'improvements'),
            $this->boolean('city.improvement_capacity_exceeded', 'Improvement capacity exceeded', 'City capacity', [
                'self' => ['infrastructure', ...$this->cityImprovementColumns()],
            ]),
            $this->boolean('city.infrastructure_aligned', 'Infrastructure aligned to 50', 'City alignment', ['self' => ['infrastructure']]),
            $this->boolean('city.land_aligned', 'Land aligned to 50', 'City alignment', ['self' => ['land']]),
            $this->boolean('city.infrastructure_and_land_aligned', 'Infrastructure and land aligned', 'City alignment', ['self' => ['infrastructure', 'land']]),
            $this->boolean('city.land_at_least_infrastructure', 'Land at least matches infrastructure', 'City alignment', ['self' => ['infrastructure', 'land']]),
            $this->number('nation.id', 'Nation ID', 'Nation', ['nation' => ['id']], 'ID'),
            $this->text('nation.nation_name', 'Nation name', 'Nation', ['nation' => ['id', 'nation_name']]),
            $this->text('nation.leader_name', 'Leader name', 'Nation', ['nation' => ['id', 'leader_name']]),
            $this->number('nation.score', 'Nation score', 'Nation', ['nation' => ['id', 'score']], 'score'),
            $this->number('nation.num_cities', 'Nation city count', 'Nation', ['nation' => ['id', 'num_cities']], 'cities'),
            $this->enum('nation.color', 'Nation color', 'Nation', ['nation' => ['id', 'color']], [
                'AQUA', 'BEIGE', 'BLACK', 'BLUE', 'BROWN', 'GRAY', 'GREEN', 'LIME', 'MAROON', 'OLIVE',
                'ORANGE', 'PINK', 'PURPLE', 'RED', 'WHITE', 'YELLOW',
            ]),
            $this->collection('nation.projects', 'Nation-owned projects', 'Projects', ['nation' => ['id', 'project_bits']], PWHelperService::projects()),
        ];

        foreach ($this->cityImprovementColumns() as $column) {
            $fields[] = $this->number(
                "city.{$column}",
                $this->humanize($column),
                'Improvements',
                ['self' => [$column]],
                'improvements',
            );
        }

        return $this->sortAndKey($fields);
    }

    /**
     * @param  array<string, array<int, string>>  $dependencies
     * @return array<string, mixed>
     */
    private function number(string $key, string $label, string $category, array $dependencies, string $unit = ''): array
    {
        return $this->field($key, $label, $category, 'number', $dependencies, [
            'gt', 'gte', 'lt', 'lte', 'eq', 'neq', 'between', 'not_between', 'multiple_of',
            'not_multiple_of', 'is_present', 'is_missing',
        ], unit: $unit);
    }

    /**
     * @param  array<string, array<int, string>>  $dependencies
     * @param  array<int, string>  $values
     * @return array<string, mixed>
     */
    private function enum(string $key, string $label, string $category, array $dependencies, array $values): array
    {
        return $this->field($key, $label, $category, 'enum', $dependencies, [
            'eq', 'neq', 'in', 'not_in', 'is_present', 'is_missing',
        ], $this->options($values));
    }

    /**
     * @param  array<string, array<int, string>>  $dependencies
     * @return array<string, mixed>
     */
    private function text(string $key, string $label, string $category, array $dependencies): array
    {
        return $this->field($key, $label, $category, 'text', $dependencies, [
            'eq', 'neq', 'in', 'not_in', 'is_present', 'is_missing',
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $dependencies
     * @return array<string, mixed>
     */
    private function boolean(string $key, string $label, string $category, array $dependencies): array
    {
        return $this->field($key, $label, $category, 'boolean', $dependencies, [
            'is_true', 'is_false', 'is_present', 'is_missing',
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $dependencies
     * @param  array<int, string>  $values
     * @return array<string, mixed>
     */
    private function collection(string $key, string $label, string $category, array $dependencies, array $values): array
    {
        return $this->field($key, $label, $category, 'collection', $dependencies, [
            'contains_all', 'contains_any', 'contains_none', 'is_present', 'is_missing',
        ], $this->options($values));
    }

    /**
     * @param  array<string, array<int, string>>  $dependencies
     * @return array<string, mixed>
     */
    private function datetime(string $key, string $label, string $category, array $dependencies): array
    {
        return $this->field($key, $label, $category, 'datetime', $dependencies, [
            'before', 'after', 'older_than', 'newer_than', 'is_present', 'is_missing',
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $dependencies
     * @param  array<int, string>  $operators
     * @param  array<int, array{value: string, label: string}>  $options
     * @return array<string, mixed>
     */
    private function field(
        string $key,
        string $label,
        string $category,
        string $type,
        array $dependencies,
        array $operators,
        array $options = [],
        string $unit = '',
    ): array {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'type' => $type,
            'operators' => $operators,
            'options' => $options,
            'unit' => $unit,
            'member_safe' => true,
            'dependencies' => $dependencies,
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function sortAndKey(array $fields): array
    {
        usort($fields, static fn (array $left, array $right): int => [
            $left['category'], $left['label'],
        ] <=> [
            $right['category'], $right['label'],
        ]);

        $catalog = [];

        foreach ($fields as $field) {
            $catalog[$field['key']] = $field;
        }

        return $catalog;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $values): array
    {
        return array_map(fn (string $value): array => [
            'value' => $value,
            'label' => $this->humanize($value),
        ], $values);
    }

    /**
     * @return array<int, string>
     */
    private function cityImprovementColumns(): array
    {
        return [
            'oil_power', 'wind_power', 'coal_power', 'nuclear_power', 'coal_mine', 'oil_well', 'uranium_mine',
            'farm', 'barracks', 'police_station', 'hospital', 'recycling_center', 'subway', 'supermarket',
            'bank', 'shopping_mall', 'stadium', 'lead_mine', 'iron_mine', 'bauxite_mine', 'oil_refinery',
            'aluminum_refinery', 'steel_mill', 'munitions_factory', 'factory', 'hangar', 'drydock',
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, value_type: string}>
     */
    private function operatorCatalog(): array
    {
        return [
            ['value' => 'gt', 'label' => 'is greater than', 'value_type' => 'number'],
            ['value' => 'gte', 'label' => 'is at least', 'value_type' => 'number'],
            ['value' => 'lt', 'label' => 'is less than', 'value_type' => 'number'],
            ['value' => 'lte', 'label' => 'is at most', 'value_type' => 'number'],
            ['value' => 'eq', 'label' => 'equals', 'value_type' => 'single'],
            ['value' => 'neq', 'label' => 'does not equal', 'value_type' => 'single'],
            ['value' => 'between', 'label' => 'is between', 'value_type' => 'range'],
            ['value' => 'not_between', 'label' => 'is not between', 'value_type' => 'range'],
            ['value' => 'multiple_of', 'label' => 'is a multiple of', 'value_type' => 'number'],
            ['value' => 'not_multiple_of', 'label' => 'is not a multiple of', 'value_type' => 'number'],
            ['value' => 'in', 'label' => 'is one of', 'value_type' => 'multi'],
            ['value' => 'not_in', 'label' => 'is not one of', 'value_type' => 'multi'],
            ['value' => 'contains_all', 'label' => 'contains all', 'value_type' => 'multi'],
            ['value' => 'contains_any', 'label' => 'contains any', 'value_type' => 'multi'],
            ['value' => 'contains_none', 'label' => 'contains none', 'value_type' => 'multi'],
            ['value' => 'is_true', 'label' => 'is yes', 'value_type' => 'none'],
            ['value' => 'is_false', 'label' => 'is no', 'value_type' => 'none'],
            ['value' => 'is_present', 'label' => 'is available', 'value_type' => 'none'],
            ['value' => 'is_missing', 'label' => 'is missing', 'value_type' => 'none'],
            ['value' => 'before', 'label' => 'is before', 'value_type' => 'datetime'],
            ['value' => 'after', 'label' => 'is after', 'value_type' => 'datetime'],
            ['value' => 'older_than', 'label' => 'is older than', 'value_type' => 'duration'],
            ['value' => 'newer_than', 'label' => 'is newer than', 'value_type' => 'duration'],
        ];
    }

    private function humanize(string $value): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $value)));
    }
}

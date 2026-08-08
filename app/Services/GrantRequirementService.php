<?php

namespace App\Services;

use App\Models\Nation;
use App\Services\GrantRequirements\GrantRequirementBuilderCatalog;
use App\Services\Rules\RuleTreeKernel;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class GrantRequirementService
{
    private const MAX_DEPTH = RuleTreeKernel::MAX_DEPTH;

    private const MAX_NODES = RuleTreeKernel::MAX_NODES;

    private const MAX_GROUP_CHILDREN = RuleTreeKernel::MAX_GROUP_CHILDREN;

    private const MAX_MULTI_VALUES = RuleTreeKernel::MAX_MULTI_VALUES;

    private readonly GrantRequirementBuilderCatalog $builderCatalog;

    public function __construct(
        private readonly RuleTreeKernel $kernel,
        ?GrantRequirementBuilderCatalog $builderCatalog = null,
    ) {
        $this->builderCatalog = $builderCatalog ?? new GrantRequirementBuilderCatalog;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBuilderConfig(): array
    {
        return $this->builderCatalog->getBuilderConfig($this->emptyTree());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function emptyTree(): ?array
    {
        return [
            'group' => 'all',
            'rules' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $definition): ?array
    {
        $inspection = $this->inspect($definition);

        if ($inspection['errors'] !== []) {
            throw new InvalidArgumentException(implode(' ', $inspection['errors']));
        }

        return $inspection['normalized'];
    }

    /**
     * @return array{normalized: array<string, mixed>|null, errors: array<int, string>}
     */
    public function inspect(mixed $definition): array
    {
        if ($definition === null || $definition === '' || $definition === []) {
            return ['normalized' => null, 'errors' => []];
        }

        if (! is_array($definition)) {
            return [
                'normalized' => null,
                'errors' => ['Grant requirements must be an array of groups and conditions.'],
            ];
        }

        $definition = $this->coerceLegacyDefinition($definition);

        if ($definition === null) {
            return ['normalized' => null, 'errors' => []];
        }

        $nodeCount = 0;
        $errors = [];
        $normalized = $this->inspectNode($definition, 'requirements', 1, $nodeCount, $errors);

        if ($normalized !== null && $this->isEmptyGroup($normalized)) {
            $normalized = null;
        }

        return [
            'normalized' => $normalized,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array{passes: bool, failures: array<int, string>, summary: array<int, string>}
     */
    public function evaluate(mixed $definition, Nation $nation): array
    {
        $inspection = $this->inspect($definition);

        if ($inspection['errors'] !== []) {
            return [
                'passes' => false,
                'failures' => ['This grant has invalid eligibility requirements. Contact an administrator.'],
                'summary' => [],
            ];
        }

        $normalized = $inspection['normalized'];

        if ($normalized === null) {
            return [
                'passes' => true,
                'failures' => [],
                'summary' => [],
            ];
        }

        $context = $this->buildContext($nation);
        $evaluation = $this->evaluateNode($normalized, $context);

        return [
            'passes' => $evaluation['passes'],
            'failures' => $evaluation['failures'],
            'summary' => $this->summarize($normalized),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function assertEligible(mixed $definition, Nation $nation): void
    {
        $evaluation = $this->evaluate($definition, $nation);

        if ($evaluation['passes']) {
            return;
        }

        throw ValidationException::withMessages([
            'grant' => $evaluation['failures'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function summarize(mixed $definition): array
    {
        $normalized = $this->normalize($definition);

        if ($normalized === null) {
            return [];
        }

        if (isset($normalized['group'], $normalized['rules']) && is_array($normalized['rules'])) {
            return collect($normalized['rules'])
                ->map(fn (array $node): string => $this->describeNode($node))
                ->filter()
                ->values()
                ->all();
        }

        return [$this->describeNode($normalized)];
    }

    /**
     * Return projects that every qualifying path requires the nation to own.
     *
     * @return array<int, string>
     */
    public function guaranteedProjects(mixed $definition): array
    {
        $inspection = $this->inspect($definition);

        if ($inspection['errors'] !== [] || $inspection['normalized'] === null) {
            return [];
        }

        return $this->guaranteedProjectsForNode($inspection['normalized']);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @return array{passes: bool, failures: array<int, string>}
     */
    private function evaluateNode(array $node, array $context): array
    {
        if (isset($node['group'])) {
            return $this->evaluateGroup($node, $context);
        }

        return $this->evaluateCondition($node, $context);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<int, string>
     */
    private function guaranteedProjectsForNode(array $node): array
    {
        if (! isset($node['group'])) {
            if (($node['field'] ?? null) !== 'projects') {
                return [];
            }

            $values = array_values(array_unique(array_map(
                static fn (mixed $value): string => (string) $value,
                is_array($node['value'] ?? null) ? $node['value'] : []
            )));

            return match ($node['operator'] ?? null) {
                'contains_all' => $values,
                'contains_any' => count($values) === 1 ? $values : [],
                default => [],
            };
        }

        if (($node['group'] ?? null) === 'not') {
            return [];
        }

        /** @var array<int, array<string, mixed>> $children */
        $children = $node['rules'] ?? [];

        if ($children === []) {
            return [];
        }

        $guaranteedByChild = array_map(
            fn (array $child): array => $this->guaranteedProjectsForNode($child),
            $children
        );

        if ($node['group'] === 'all') {
            return array_values(array_unique(array_merge(...$guaranteedByChild)));
        }

        $intersection = array_shift($guaranteedByChild) ?? [];

        foreach ($guaranteedByChild as $projects) {
            $intersection = array_values(array_intersect($intersection, $projects));
        }

        return $intersection;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @return array{passes: bool, failures: array<int, string>}
     */
    private function evaluateGroup(array $node, array $context): array
    {
        /** @var array<int, array<string, mixed>> $children */
        $children = $node['rules'];

        if ($children === []) {
            return ['passes' => true, 'failures' => []];
        }

        if ($node['group'] === 'all') {
            $failures = [];

            foreach ($children as $child) {
                $result = $this->evaluateNode($child, $context);

                if (! $result['passes']) {
                    $failures = [...$failures, ...$result['failures']];
                }
            }

            return ['passes' => $failures === [], 'failures' => array_values(array_unique($failures))];
        }

        if ($node['group'] === 'any') {
            $matches = [];

            foreach ($children as $child) {
                $result = $this->evaluateNode($child, $context);
                $matches[] = $result['passes'];

                if ($result['passes']) {
                    return ['passes' => true, 'failures' => []];
                }
            }

            if ($this->kernel->groupMatches('any', $matches)) {
                return ['passes' => true, 'failures' => []];
            }

            return [
                'passes' => false,
                'failures' => [
                    'At least one of the following must be true: '.$this->describeChildList($children),
                ],
            ];
        }

        $matches = [];

        foreach ($children as $child) {
            $result = $this->evaluateNode($child, $context);
            $matches[] = $result['passes'];

            if ($result['passes']) {
                return [
                    'passes' => false,
                    'failures' => [
                        'None of the following may be true: '.$this->describeChildList($children),
                    ],
                ];
            }
        }

        if (! $this->kernel->groupMatches('not', $matches)) {
            return [
                'passes' => false,
                'failures' => [
                    'None of the following may be true: '.$this->describeChildList($children),
                ],
            ];
        }

        return ['passes' => true, 'failures' => []];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @return array{passes: bool, failures: array<int, string>}
     */
    private function evaluateCondition(array $node, array $context): array
    {
        $field = $this->builderCatalog->fields()[$node['field']];
        $actual = $context[$node['field']] ?? null;
        $operator = $node['operator'];
        $value = $node['value'];

        $passes = match ($field['type']) {
            'number' => $this->evaluateNumberCondition($actual, $operator, $value),
            'enum' => $this->evaluateEnumCondition($actual, $operator, $value),
            'collection' => $this->evaluateCollectionCondition($actual, $operator, $value),
            default => false,
        };

        if ($passes) {
            return ['passes' => true, 'failures' => []];
        }

        return [
            'passes' => false,
            'failures' => [$this->resolveFailureMessage($node, $field, $actual)],
        ];
    }

    private function evaluateNumberCondition(mixed $actual, string $operator, mixed $value): bool
    {
        $actualNumber = (float) ($actual ?? 0);

        return match ($operator) {
            'gt' => $actualNumber > (float) $value,
            'gte' => $actualNumber >= (float) $value,
            'lt' => $actualNumber < (float) $value,
            'lte' => $actualNumber <= (float) $value,
            'eq' => abs($actualNumber - (float) $value) < 0.0001,
            'neq' => abs($actualNumber - (float) $value) >= 0.0001,
            'between' => $actualNumber >= (float) $value['min'] && $actualNumber <= (float) $value['max'],
            'not_between' => $actualNumber < (float) $value['min'] || $actualNumber > (float) $value['max'],
            default => false,
        };
    }

    private function evaluateEnumCondition(mixed $actual, string $operator, mixed $value): bool
    {
        $actualValue = strtoupper((string) ($actual ?? ''));

        return match ($operator) {
            'eq' => $actualValue === strtoupper((string) $value),
            'neq' => $actualValue !== strtoupper((string) $value),
            'in' => in_array($actualValue, array_map('strtoupper', $value), true),
            'not_in' => ! in_array($actualValue, array_map('strtoupper', $value), true),
            default => false,
        };
    }

    private function evaluateCollectionCondition(mixed $actual, string $operator, mixed $value): bool
    {
        $actualValues = collect(is_array($actual) ? $actual : [])
            ->map(fn (mixed $item): string => strtoupper((string) $item))
            ->values();

        $expected = collect(is_array($value) ? $value : [$value])
            ->map(fn (mixed $item): string => strtoupper((string) $item))
            ->values();

        return match ($operator) {
            'contains_all' => $expected->every(fn (string $item): bool => $actualValues->contains($item)),
            'contains_any' => $expected->contains(fn (string $item): bool => $actualValues->contains($item)),
            'contains_none' => $expected->every(fn (string $item): bool => ! $actualValues->contains($item)),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $field
     */
    private function resolveFailureMessage(array $node, array $field, mixed $actual): string
    {
        $customMessage = trim((string) ($node['message'] ?? ''));

        if ($customMessage !== '') {
            return $customMessage;
        }

        $label = $field['label'];
        $operator = $node['operator'];
        $value = $node['value'];

        return match ($field['type']) {
            'number' => match ($operator) {
                'gt' => "{$label} must be greater than {$value}.",
                'gte' => "{$label} must be at least {$value}.",
                'lt' => "{$label} must be less than {$value}.",
                'lte' => "{$label} must be at most {$value}.",
                'eq' => "{$label} must be exactly {$value}.",
                'neq' => "{$label} must not be {$value}.",
                'between' => "{$label} must be between {$value['min']} and {$value['max']}.",
                'not_between' => "{$label} must not be between {$value['min']} and {$value['max']}.",
                default => "{$label} did not satisfy this grant requirement.",
            },
            'enum' => match ($operator) {
                'eq' => "{$label} must be {$this->builderCatalog->displayValue($value)}.",
                'neq' => "{$label} must not be {$this->builderCatalog->displayValue($value)}.",
                'in' => "{$label} must be one of: ".$this->builderCatalog->displayList($value).'.',
                'not_in' => "{$label} must not be one of: ".$this->builderCatalog->displayList($value).'.',
                default => "{$label} did not satisfy this grant requirement.",
            },
            'collection' => match ($operator) {
                'contains_all' => "You must have all of these {$label}: ".$this->builderCatalog->displayList($value).'.',
                'contains_any' => "You must have at least one of these {$label}: ".$this->builderCatalog->displayList($value).'.',
                'contains_none' => "You must not have any of these {$label}: ".$this->builderCatalog->displayList($value).'.',
                default => "{$label} did not satisfy this grant requirement.",
            },
            default => $actual !== null ? "{$label} did not satisfy this grant requirement." : "{$label} is unavailable for this grant requirement.",
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function describeNode(array $node): string
    {
        if (isset($node['group'])) {
            $mode = match ($node['group']) {
                'all' => 'All of',
                'any' => 'Any of',
                'not' => 'None of',
            };

            return $mode.': '.$this->describeChildList($node['rules']);
        }

        $field = $this->builderCatalog->fields()[$node['field']];
        $label = $field['label'];
        $operator = $node['operator'];
        $value = $node['value'];

        return match ($field['type']) {
            'number' => match ($operator) {
                'gt' => "{$label} > {$value}",
                'gte' => "{$label} >= {$value}",
                'lt' => "{$label} < {$value}",
                'lte' => "{$label} <= {$value}",
                'eq' => "{$label} = {$value}",
                'neq' => "{$label} is not {$value}",
                'between' => "{$label} between {$value['min']} and {$value['max']}",
                'not_between' => "{$label} outside {$value['min']} to {$value['max']}",
                default => $label,
            },
            'enum' => match ($operator) {
                'eq' => "{$label} is {$this->builderCatalog->displayValue($value)}",
                'neq' => "{$label} is not {$this->builderCatalog->displayValue($value)}",
                'in' => "{$label} is one of ".$this->builderCatalog->displayList($value),
                'not_in' => "{$label} is not one of ".$this->builderCatalog->displayList($value),
                default => $label,
            },
            'collection' => match ($operator) {
                'contains_all' => 'Has all of '.$this->builderCatalog->displayList($value)." {$label}",
                'contains_any' => 'Has any of '.$this->builderCatalog->displayList($value)." {$label}",
                'contains_none' => 'Has none of '.$this->builderCatalog->displayList($value)." {$label}",
                default => $label,
            },
            default => $label,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     */
    private function describeChildList(array $children): string
    {
        return collect($children)
            ->map(fn (array $node): string => $this->describeNode($node))
            ->implode('; ');
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function isEmptyGroup(array $definition): bool
    {
        return ($definition['group'] ?? null) !== null
            && is_array($definition['rules'] ?? null)
            && $definition['rules'] === [];
    }

    /**
     * @param  array<int, string>  &$errors
     * @return array<string, mixed>|null
     */
    private function inspectNode(mixed $node, string $path, int $depth, int &$nodeCount, array &$errors): ?array
    {
        if (! is_array($node)) {
            $errors[] = 'Grant requirements must be an array of groups and conditions.';

            return null;
        }

        $nodeCount++;

        if ($nodeCount > self::MAX_NODES) {
            $errors[] = 'Grant requirements contain too many conditions.';

            return null;
        }

        if ($depth > self::MAX_DEPTH) {
            $errors[] = 'Grant requirements are nested too deeply.';

            return null;
        }

        if (isset($node['group'])) {
            return $this->inspectGroupNode($node, $path, $depth, $nodeCount, $errors);
        }

        return $this->inspectConditionNode($node, $path, $errors);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  &$errors
     * @return array<string, mixed>|null
     */
    private function inspectGroupNode(array $node, string $path, int $depth, int &$nodeCount, array &$errors): ?array
    {
        $allowedKeys = ['group', 'rules'];
        $unknownKeys = array_diff(array_keys($node), $allowedKeys);

        if ($unknownKeys !== []) {
            $errors[] = 'Grant requirement groups contain unsupported keys.';
        }

        $group = strtolower((string) ($node['group'] ?? ''));

        if (! in_array($group, ['all', 'any', 'not'], true)) {
            $errors[] = 'Grant requirement groups must be all, any, or not.';

            return null;
        }

        if (! isset($node['rules']) || ! is_array($node['rules'])) {
            $errors[] = 'Grant requirement groups must contain a rules array.';

            return null;
        }

        if ($depth > 1 && $node['rules'] === []) {
            $errors[] = 'Nested grant requirement groups must contain at least one rule.';
        }

        if (count($node['rules']) > self::MAX_GROUP_CHILDREN) {
            $errors[] = 'A grant requirement group contains too many child rules.';
        }

        $normalizedChildren = [];

        foreach ($node['rules'] as $index => $child) {
            $normalizedChild = $this->inspectNode($child, "{$path}.rules.{$index}", $depth + 1, $nodeCount, $errors);

            if ($normalizedChild !== null) {
                $normalizedChildren[] = $normalizedChild;
            }
        }

        return [
            'group' => $group,
            'rules' => $normalizedChildren,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  &$errors
     * @return array<string, mixed>|null
     */
    private function inspectConditionNode(array $node, string $path, array &$errors): ?array
    {
        $allowedKeys = ['field', 'operator', 'value', 'message'];
        $unknownKeys = array_diff(array_keys($node), $allowedKeys);

        if ($unknownKeys !== []) {
            $errors[] = 'Grant conditions contain unsupported keys.';
        }

        $fieldKey = (string) ($node['field'] ?? '');
        $field = $this->builderCatalog->fields()[$fieldKey] ?? null;

        if ($field === null) {
            $errors[] = 'Grant conditions must use a supported field.';

            return null;
        }

        $operatorKey = (string) ($node['operator'] ?? '');
        $allowedOperators = $field['operators'] ?? [];

        if (! in_array($operatorKey, $allowedOperators, true)) {
            $errors[] = "The {$field['label']} field does not support that operator.";

            return null;
        }

        $message = trim((string) ($node['message'] ?? ''));

        if (mb_strlen($message) > 255) {
            $errors[] = 'Custom requirement messages must be 255 characters or less.';
        }

        $normalizedValue = $this->inspectConditionValue($field, $operatorKey, $node['value'] ?? null, $path, $errors);

        if ($normalizedValue === null) {
            return null;
        }

        return [
            'field' => $fieldKey,
            'operator' => $operatorKey,
            'value' => $normalizedValue,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  &$errors
     * @return string|float|array<string, float>|array<int, string>|null
     */
    private function inspectConditionValue(array $field, string $operator, mixed $value, string $path, array &$errors): string|float|array|null
    {
        if ($field['type'] === 'number') {
            if (in_array($operator, ['between', 'not_between'], true)) {
                if (! is_array($value)) {
                    $errors[] = "The {$field['label']} range must include a minimum and maximum.";

                    return null;
                }

                $min = $this->normalizeNumericValue($value['min'] ?? null);
                $max = $this->normalizeNumericValue($value['max'] ?? null);

                if ($min === null || $max === null) {
                    $errors[] = "The {$field['label']} range must use numbers.";

                    return null;
                }

                if ($min > $max) {
                    $errors[] = "The {$field['label']} minimum cannot be greater than the maximum.";

                    return null;
                }

                return ['min' => $min, 'max' => $max];
            }

            $numericValue = $this->normalizeNumericValue($value);

            if ($numericValue === null) {
                $errors[] = "The {$field['label']} condition requires a numeric value.";

                return null;
            }

            return $numericValue;
        }

        if ($field['type'] === 'enum') {
            if (in_array($operator, ['in', 'not_in'], true)) {
                $normalizedValues = $this->normalizeMultiValue($value, $field['options'], false);

                if ($normalizedValues === null) {
                    $errors[] = "The {$field['label']} condition requires one or more valid options.";

                    return null;
                }

                return $normalizedValues;
            }

            $normalizedValue = $this->normalizeOptionValue($value, $field['options'], false);

            if ($normalizedValue === null) {
                $errors[] = "The {$field['label']} condition requires a valid option.";

                return null;
            }

            return $normalizedValue;
        }

        $normalizedValues = $this->normalizeMultiValue($value, $field['options'], true);

        if ($normalizedValues === null) {
            $errors[] = "The {$field['label']} condition requires one or more valid selections.";

            return null;
        }

        return $normalizedValues;
    }

    private function normalizeNumericValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }

    /**
     * @param  array<int, array{value: string, label: string}>  $options
     */
    private function normalizeOptionValue(mixed $value, array $options, bool $caseSensitive = true): ?string
    {
        $candidate = trim((string) $value);

        if ($candidate === '') {
            return null;
        }

        foreach ($options as $option) {
            $optionValue = (string) $option['value'];

            if ($caseSensitive ? $candidate === $optionValue : strtoupper($candidate) === strtoupper($optionValue)) {
                return $optionValue;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{value: string, label: string}>  $options
     * @return array<int, string>|null
     */
    private function normalizeMultiValue(mixed $value, array $options, bool $caseSensitive = true): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        if (count($value) > self::MAX_MULTI_VALUES) {
            return null;
        }

        $normalized = collect($value)
            ->map(fn (mixed $item): ?string => $this->normalizeOptionValue($item, $options, $caseSensitive))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(Nation $nation): array
    {
        $nation->loadMissing(['latestSignIn', 'military', 'resources', 'cities', 'growthCircleEnrollment']);

        $latestSignIn = $nation->latestSignIn;
        $military = $nation->military;
        $resources = $nation->resources;
        $projects = collect(PWHelperService::getNationProjects($nation->project_bits))->values()->all();
        $cityCount = max(0, (int) ($nation->num_cities ?? 0));
        $cities = $nation->cities instanceof Collection ? $nation->cities : collect($nation->cities);
        $totalInfrastructure = round((float) $cities->sum('infrastructure'), 2);
        $avgInfrastructure = $cityCount > 0 ? round($totalInfrastructure / $cityCount, 2) : 0.0;

        $context = [
            'num_cities' => (int) ($nation->num_cities ?? 0),
            'score' => round((float) ($nation->score ?? 0), 2),
            'mmr_score' => (int) ($latestSignIn?->mmr_score ?? 0),
            'alliance_seniority' => (int) ($nation->alliance_seniority ?? 0),
            'beige_turns' => (int) ($nation->beige_turns ?? 0),
            'vacation_mode_turns' => (int) ($nation->vacation_mode_turns ?? 0),
            'turns_since_last_city' => (int) ($nation->turns_since_last_city ?? 0),
            'turns_since_last_project' => (int) ($nation->turns_since_last_project ?? 0),
            'wars_won' => (int) ($nation->wars_won ?? 0),
            'wars_lost' => (int) ($nation->wars_lost ?? 0),
            'offensive_wars_count' => (int) ($nation->offensive_wars_count ?? 0),
            'defensive_wars_count' => (int) ($nation->defensive_wars_count ?? 0),
            'population' => (int) ($nation->population ?? 0),
            'gross_national_income' => round((float) ($nation->gross_national_income ?? 0), 2),
            'gross_domestic_product' => round((float) ($nation->gross_domestic_product ?? 0), 2),
            'total_infrastructure' => $totalInfrastructure,
            'avg_infrastructure_per_city' => $avgInfrastructure,
            'domestic_policy' => (string) ($nation->domestic_policy ?? ''),
            'war_policy' => (string) ($nation->war_policy ?? ''),
            'color' => (string) ($nation->color ?? ''),
            'continent' => (string) ($nation->continent ?? ''),
            'alliance_position' => (string) ($nation->alliance_position ?? ''),
            'growth_circle_enrollment' => $nation->growthCircleEnrollment === null ? 'NOT_ENROLLED' : 'ENROLLED',
            'projects' => $projects,
            'soldiers' => (int) ($latestSignIn?->soldiers ?? $military?->soldiers ?? 0),
            'tanks' => (int) ($latestSignIn?->tanks ?? $military?->tanks ?? 0),
            'aircraft' => (int) ($latestSignIn?->aircraft ?? $military?->aircraft ?? 0),
            'ships' => (int) ($latestSignIn?->ships ?? $military?->ships ?? 0),
            'missiles' => (int) ($latestSignIn?->missiles ?? $military?->missiles ?? 0),
            'nukes' => (int) ($latestSignIn?->nukes ?? $military?->nukes ?? 0),
            'spies' => (int) ($latestSignIn?->spies ?? $military?->spies ?? 0),
        ];

        $context['soldiers_per_city'] = $cityCount > 0 ? round(((float) $context['soldiers']) / $cityCount, 2) : 0.0;
        $context['tanks_per_city'] = $cityCount > 0 ? round(((float) $context['tanks']) / $cityCount, 2) : 0.0;
        $context['aircraft_per_city'] = $cityCount > 0 ? round(((float) $context['aircraft']) / $cityCount, 2) : 0.0;
        $context['ships_per_city'] = $cityCount > 0 ? round(((float) $context['ships']) / $cityCount, 2) : 0.0;
        $context['missiles_per_city'] = $cityCount > 0 ? round(((float) $context['missiles']) / $cityCount, 2) : 0.0;
        $context['nukes_per_city'] = $cityCount > 0 ? round(((float) $context['nukes']) / $cityCount, 2) : 0.0;

        foreach (PWHelperService::resources(true, true) as $resource) {
            $context[$resource] = round((float) ($latestSignIn?->{$resource} ?? $resources?->{$resource} ?? 0), 2);
        }

        return $context;
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function coerceLegacyDefinition(array $definition): ?array
    {
        if ($definition === []) {
            return [];
        }

        if (isset($definition['group']) || isset($definition['field']) || array_is_list($definition)) {
            return $definition;
        }

        $rules = [];
        $minCities = $this->normalizeNumericValue($definition['min_cities'] ?? null);
        $maxCities = $this->normalizeNumericValue($definition['max_cities'] ?? null);
        $minScore = $this->normalizeNumericValue($definition['min_score'] ?? null);
        $maxScore = $this->normalizeNumericValue($definition['max_score'] ?? null);
        $minMmr = $this->normalizeNumericValue($definition['min_mmr_score'] ?? $definition['mmr_score'] ?? null);
        $maxMmr = $this->normalizeNumericValue($definition['max_mmr_score'] ?? null);
        $minimumInfrastructurePerCity = $this->normalizeNumericValue($definition['minimum_infra_per_city'] ?? null);

        if ($minCities !== null && $minCities > 0) {
            $rules[] = ['field' => 'num_cities', 'operator' => 'gte', 'value' => $minCities, 'message' => ''];
        }

        if ($maxCities !== null && $maxCities > 0) {
            $rules[] = ['field' => 'num_cities', 'operator' => 'lte', 'value' => $maxCities, 'message' => ''];
        }

        if ($minScore !== null && $minScore > 0) {
            $rules[] = ['field' => 'score', 'operator' => 'gte', 'value' => $minScore, 'message' => ''];
        }

        if ($maxScore !== null && $maxScore > 0) {
            $rules[] = ['field' => 'score', 'operator' => 'lte', 'value' => $maxScore, 'message' => ''];
        }

        if ($minMmr !== null && $minMmr > 0) {
            $rules[] = ['field' => 'mmr_score', 'operator' => 'gte', 'value' => $minMmr, 'message' => ''];
        }

        if ($maxMmr !== null && $maxMmr > 0) {
            $rules[] = ['field' => 'mmr_score', 'operator' => 'lte', 'value' => $maxMmr, 'message' => ''];
        }

        if ($minimumInfrastructurePerCity !== null && $minimumInfrastructurePerCity > 0) {
            $rules[] = [
                'field' => 'avg_infrastructure_per_city',
                'operator' => 'gte',
                'value' => $minimumInfrastructurePerCity,
                'message' => '',
            ];
        }

        $requiredProjects = $this->normalizeLegacySelections($definition['required_projects'] ?? $definition['projects'] ?? null, 'projects');

        if ($requiredProjects !== []) {
            $rules[] = ['field' => 'projects', 'operator' => 'contains_all', 'value' => $requiredProjects, 'message' => ''];
        }

        $blockedProjects = $this->normalizeLegacySelections($definition['forbidden_projects'] ?? null, 'projects');

        if ($blockedProjects !== []) {
            $rules[] = ['field' => 'projects', 'operator' => 'contains_none', 'value' => $blockedProjects, 'message' => ''];
        }

        $allowedColors = $this->normalizeLegacySelections($definition['allowed_colors'] ?? null, 'color');

        if ($allowedColors !== []) {
            $rules[] = ['field' => 'color', 'operator' => 'in', 'value' => $allowedColors, 'message' => ''];
        }

        $domesticPolicy = $this->normalizeLegacySingleSelection($definition['government_type'] ?? $definition['domestic_policy'] ?? null, 'domestic_policy');

        if ($domesticPolicy !== null) {
            $rules[] = ['field' => 'domestic_policy', 'operator' => 'eq', 'value' => $domesticPolicy, 'message' => ''];
        }

        $warPolicy = $this->normalizeLegacySingleSelection($definition['war_policy'] ?? null, 'war_policy');

        if ($warPolicy !== null) {
            $rules[] = ['field' => 'war_policy', 'operator' => 'eq', 'value' => $warPolicy, 'message' => ''];
        }

        if ($rules === []) {
            return null;
        }

        return [
            'group' => 'all',
            'rules' => $rules,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeLegacySelections(mixed $value, string $fieldKey): array
    {
        $field = $this->builderCatalog->fields()[$fieldKey] ?? null;

        if ($field === null) {
            return [];
        }

        return $this->normalizeMultiValue($value, $field['options'], false) ?? [];
    }

    private function normalizeLegacySingleSelection(mixed $value, string $fieldKey): ?string
    {
        $field = $this->builderCatalog->fields()[$fieldKey] ?? null;

        if ($field === null) {
            return null;
        }

        return $this->normalizeOptionValue($value, $field['options'], false);
    }
}

<?php

namespace App\Services\Rules;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class RuleTreeKernel
{
    public const MAX_DEPTH = 5;

    public const MAX_NODES = 100;

    public const MAX_GROUP_CHILDREN = 25;

    public const MAX_MULTI_VALUES = 50;

    /**
     * Shared truth semantics for commutative rule groups.
     *
     * @param  array<int, bool>  $matches
     */
    public function groupMatches(string $group, array $matches): bool
    {
        return match ($group) {
            'all' => ! in_array(false, $matches, true),
            'any' => in_array(true, $matches, true),
            'not' => ! in_array(true, $matches, true),
            default => false,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldCatalog
     * @return array{normalized: array<string, mixed>|null, errors: array<int, string>}
     */
    public function inspectAuditDefinition(mixed $definition, array $fieldCatalog): array
    {
        if (! is_array($definition)) {
            return [
                'normalized' => null,
                'errors' => ['The rule definition must be an object containing alert and exception conditions.'],
            ];
        }

        $errors = [];
        $unknownKeys = array_diff(array_keys($definition), ['schema_version', 'criteria', 'exceptions']);

        if ($unknownKeys !== []) {
            $errors[] = 'The rule definition contains unsupported keys: '.implode(', ', $unknownKeys).'.';
        }

        if ((int) ($definition['schema_version'] ?? 0) !== 1) {
            $errors[] = 'The rule definition must use schema version 1.';
        }

        $nodeCount = 0;
        $seenIds = [];
        $criteria = $this->inspectNode(
            $definition['criteria'] ?? null,
            'criteria',
            1,
            $nodeCount,
            $seenIds,
            $fieldCatalog,
            $errors,
            true,
        );
        $exceptions = $this->inspectNode(
            $definition['exceptions'] ?? null,
            'exceptions',
            1,
            $nodeCount,
            $seenIds,
            $fieldCatalog,
            $errors,
            true,
        );

        if ($criteria !== null && ! isset($criteria['group'])) {
            $errors[] = 'Alert conditions must begin with an all or any group.';
            $criteria = null;
        }

        if ($exceptions !== null && ! isset($exceptions['group'])) {
            $errors[] = 'Exceptions must begin with an all or any group.';
            $exceptions = null;
        }

        if ($criteria === null || $exceptions === null || $errors !== []) {
            return [
                'normalized' => null,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        return [
            'normalized' => [
                'schema_version' => 1,
                'criteria' => $criteria,
                'exceptions' => $exceptions,
            ],
            'errors' => [],
        ];
    }

    /**
     * Evaluate a normalized group and collect condition-level evidence.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $context
     * @param  array<string, array<string, mixed>>  $fieldCatalog
     * @return array{matched: bool, warnings: array<int, string>, evidence: array<int, array<string, mixed>>}
     */
    public function evaluateGroup(
        array $group,
        array $context,
        array $fieldCatalog,
        string $scope,
        ?Carbon $evaluatedAt = null,
    ): array {
        $evaluatedAt ??= now();

        return $this->evaluateNode($group, $context, $fieldCatalog, $scope, $evaluatedAt);
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<string, array<string, mixed>>  $fieldCatalog
     */
    public function describeTree(array $tree, array $fieldCatalog): string
    {
        return $this->describeNode($tree, $fieldCatalog);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $fieldCatalog
     */
    public function describeNode(array $node, array $fieldCatalog): string
    {
        if (isset($node['group'])) {
            /** @var array<int, array<string, mixed>> $children */
            $children = $node['rules'] ?? [];

            if ($children === []) {
                return 'No conditions';
            }

            $label = ($node['group'] ?? 'all') === 'any' ? 'Any of' : 'All of';
            $descriptions = array_map(
                fn (array $child): string => $this->describeNode($child, $fieldCatalog),
                $children,
            );

            return $label.': '.implode('; ', $descriptions);
        }

        $field = $fieldCatalog[(string) ($node['field'] ?? '')] ?? [
            'label' => 'Unknown field',
            'type' => 'text',
        ];

        return $this->conditionSentence($node, $field);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $field
     */
    public function conditionSentence(array $node, array $field): string
    {
        $label = (string) ($field['label'] ?? $node['field'] ?? 'Value');
        $operator = (string) ($node['operator'] ?? 'eq');
        $value = $node['value'] ?? null;
        $display = $this->displayValue($value, $field);

        return match ($operator) {
            'gt' => "{$label} is greater than {$display}",
            'gte' => "{$label} is at least {$display}",
            'lt' => "{$label} is less than {$display}",
            'lte' => "{$label} is at most {$display}",
            'eq' => "{$label} equals {$display}",
            'neq' => "{$label} does not equal {$display}",
            'between' => "{$label} is between {$this->displayValue($value['min'] ?? null, $field)} and {$this->displayValue($value['max'] ?? null, $field)}",
            'not_between' => "{$label} is not between {$this->displayValue($value['min'] ?? null, $field)} and {$this->displayValue($value['max'] ?? null, $field)}",
            'multiple_of' => "{$label} is a multiple of {$display}",
            'not_multiple_of' => "{$label} is not a multiple of {$display}",
            'in' => "{$label} is one of {$display}",
            'not_in' => "{$label} is not one of {$display}",
            'contains_all' => "{$label} contains all of {$display}",
            'contains_any' => "{$label} contains any of {$display}",
            'contains_none' => "{$label} contains none of {$display}",
            'is_true' => "{$label} is yes",
            'is_false' => "{$label} is no",
            'is_present' => "{$label} is available",
            'is_missing' => "{$label} is missing",
            'before' => "{$label} is before {$display}",
            'after' => "{$label} is after {$display}",
            'older_than' => "{$label} is older than {$display}",
            'newer_than' => "{$label} is newer than {$display}",
            default => $label,
        };
    }

    /**
     * Canonicalize a definition for behavior comparison. Node IDs and commutative ordering are removed.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function canonicalizeAuditDefinition(array $definition): array
    {
        return [
            'schema_version' => 1,
            'criteria' => $this->canonicalizeNode($definition['criteria'] ?? []),
            'exceptions' => $this->canonicalizeNode($definition['exceptions'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    public function referencedFields(array $definition): array
    {
        $fields = [];
        $this->collectFields($definition['criteria'] ?? [], $fields);
        $this->collectFields($definition['exceptions'] ?? [], $fields);

        sort($fields, SORT_STRING);

        return array_values(array_unique($fields));
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldCatalog
     * @param  array<int, string>  &$errors
     * @param  array<string, bool>  &$seenIds
     * @return array<string, mixed>|null
     */
    private function inspectNode(
        mixed $node,
        string $path,
        int $depth,
        int &$nodeCount,
        array &$seenIds,
        array $fieldCatalog,
        array &$errors,
        bool $root = false,
    ): ?array {
        if (! is_array($node)) {
            $errors[] = "{$path} must be a group or condition.";

            return null;
        }

        $nodeCount++;

        if ($nodeCount > self::MAX_NODES) {
            $errors[] = 'A rule may contain at most '.self::MAX_NODES.' groups and conditions.';

            return null;
        }

        if ($depth > self::MAX_DEPTH) {
            $errors[] = 'Rule groups may be nested at most '.self::MAX_DEPTH.' levels deep.';

            return null;
        }

        if (array_key_exists('group', $node)) {
            return $this->inspectGroup($node, $path, $depth, $nodeCount, $seenIds, $fieldCatalog, $errors, $root);
        }

        return $this->inspectCondition($node, $path, $seenIds, $fieldCatalog, $errors);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $fieldCatalog
     * @param  array<int, string>  &$errors
     * @param  array<string, bool>  &$seenIds
     * @return array<string, mixed>|null
     */
    private function inspectGroup(
        array $node,
        string $path,
        int $depth,
        int &$nodeCount,
        array &$seenIds,
        array $fieldCatalog,
        array &$errors,
        bool $root,
    ): ?array {
        $unknownKeys = array_diff(array_keys($node), ['id', 'group', 'rules']);

        if ($unknownKeys !== []) {
            $errors[] = "{$path} contains unsupported keys: ".implode(', ', $unknownKeys).'.';
        }

        $group = strtolower(trim((string) ($node['group'] ?? '')));

        if (! in_array($group, ['all', 'any'], true)) {
            $errors[] = "{$path} must use either all or any.";

            return null;
        }

        $id = $this->inspectNodeId($node['id'] ?? null, $path, $seenIds, $errors, $root);

        if (! isset($node['rules']) || ! is_array($node['rules']) || ! array_is_list($node['rules'])) {
            $errors[] = "{$path} must contain a list of rules.";

            return null;
        }

        if (! $root && $node['rules'] === []) {
            $errors[] = "{$path} must contain at least one condition.";
        }

        if (count($node['rules']) > self::MAX_GROUP_CHILDREN) {
            $errors[] = "{$path} may contain at most ".self::MAX_GROUP_CHILDREN.' direct children.';
        }

        $children = [];

        foreach ($node['rules'] as $index => $child) {
            $normalized = $this->inspectNode(
                $child,
                "{$path}.rules.{$index}",
                $depth + 1,
                $nodeCount,
                $seenIds,
                $fieldCatalog,
                $errors,
            );

            if ($normalized !== null) {
                $children[] = $normalized;
            }
        }

        return array_filter([
            'id' => $id,
            'group' => $group,
            'rules' => $children,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $fieldCatalog
     * @param  array<int, string>  &$errors
     * @param  array<string, bool>  &$seenIds
     * @return array<string, mixed>|null
     */
    private function inspectCondition(
        array $node,
        string $path,
        array &$seenIds,
        array $fieldCatalog,
        array &$errors,
    ): ?array {
        $unknownKeys = array_diff(array_keys($node), ['id', 'field', 'operator', 'value']);

        if ($unknownKeys !== []) {
            $errors[] = "{$path} contains unsupported keys: ".implode(', ', $unknownKeys).'.';
        }

        $id = $this->inspectNodeId($node['id'] ?? null, $path, $seenIds, $errors);
        $fieldKey = trim((string) ($node['field'] ?? ''));
        $field = $fieldCatalog[$fieldKey] ?? null;

        if ($field === null) {
            $errors[] = "{$path} must use a supported field.";

            return null;
        }

        $operator = trim((string) ($node['operator'] ?? ''));

        if (! in_array($operator, $field['operators'] ?? [], true)) {
            $errors[] = "{$path}: {$field['label']} does not support that comparison.";

            return null;
        }

        $value = $this->inspectValue($field, $operator, $node['value'] ?? null, $path, $errors);

        if ($value === self::invalidValue()) {
            return null;
        }

        return [
            'id' => $id,
            'field' => $fieldKey,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * @param  array<string, bool>  &$seenIds
     * @param  array<int, string>  &$errors
     */
    private function inspectNodeId(mixed $value, string $path, array &$seenIds, array &$errors, bool $optional = false): ?string
    {
        if ($optional && ($value === null || $value === '')) {
            return null;
        }

        $id = trim((string) $value);

        if (! Str::isUuid($id)) {
            $errors[] = "{$path} must have a valid unique ID.";

            return $id !== '' ? $id : null;
        }

        if (isset($seenIds[$id])) {
            $errors[] = "{$path} repeats a condition or group ID.";
        }

        $seenIds[$id] = true;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  &$errors
     */
    private function inspectValue(array $field, string $operator, mixed $value, string $path, array &$errors): mixed
    {
        if (in_array($operator, ['is_true', 'is_false', 'is_present', 'is_missing'], true)) {
            return null;
        }

        $type = (string) ($field['type'] ?? '');

        if ($type === 'number') {
            if (in_array($operator, ['between', 'not_between'], true)) {
                if (! is_array($value) || array_diff(array_keys($value), ['min', 'max']) !== []) {
                    $errors[] = "{$path}: {$field['label']} needs a minimum and maximum.";

                    return self::invalidValue();
                }

                $min = $this->normalizeNumber($value['min'] ?? null);
                $max = $this->normalizeNumber($value['max'] ?? null);

                if ($min === null || $max === null) {
                    $errors[] = "{$path}: {$field['label']} needs numeric minimum and maximum values.";

                    return self::invalidValue();
                }

                if ($min > $max) {
                    $errors[] = "{$path}: the minimum cannot be greater than the maximum.";

                    return self::invalidValue();
                }

                return ['min' => $min, 'max' => $max];
            }

            $number = $this->normalizeNumber($value);

            if ($number === null) {
                $errors[] = "{$path}: {$field['label']} needs a number.";

                return self::invalidValue();
            }

            if (in_array($operator, ['multiple_of', 'not_multiple_of'], true) && (float) $number === 0.0) {
                $errors[] = "{$path}: a multiple cannot be zero.";

                return self::invalidValue();
            }

            return $number;
        }

        if ($type === 'datetime') {
            if (in_array($operator, ['older_than', 'newer_than'], true)) {
                return $this->normalizeDuration($value, $path, $field, $errors);
            }

            try {
                $date = Carbon::parse((string) $value);
            } catch (Throwable) {
                $date = null;
            }

            if ($date === null) {
                $errors[] = "{$path}: {$field['label']} needs a valid date and time.";

                return self::invalidValue();
            }

            return $date->utc()->toIso8601String();
        }

        if (in_array($operator, ['in', 'not_in', 'contains_all', 'contains_any', 'contains_none'], true)) {
            if (! is_array($value) || $value === [] || count($value) > self::MAX_MULTI_VALUES) {
                $errors[] = "{$path}: {$field['label']} needs between 1 and ".self::MAX_MULTI_VALUES.' selections.';

                return self::invalidValue();
            }

            $normalized = [];

            foreach ($value as $item) {
                $selection = $this->normalizeSelection($item, $field);

                if ($selection === null) {
                    $errors[] = "{$path}: {$field['label']} contains an unsupported selection.";

                    return self::invalidValue();
                }

                $normalized[] = $selection;
            }

            return array_values(array_unique($normalized, SORT_REGULAR));
        }

        $selection = $this->normalizeSelection($value, $field);

        if ($selection === null) {
            $errors[] = "{$path}: {$field['label']} needs a valid value.";

            return self::invalidValue();
        }

        return $selection;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  &$errors
     * @return array{amount: int, unit: string}|object
     */
    private function normalizeDuration(mixed $value, string $path, array $field, array &$errors): array|object
    {
        if (! is_array($value) || array_diff(array_keys($value), ['amount', 'unit']) !== []) {
            $errors[] = "{$path}: {$field['label']} needs a duration and unit.";

            return self::invalidValue();
        }

        $amount = filter_var($value['amount'] ?? null, FILTER_VALIDATE_INT);
        $unit = strtolower(trim((string) ($value['unit'] ?? '')));

        if ($amount === false || $amount < 1 || $amount > 3650 || ! in_array($unit, ['hours', 'days', 'weeks', 'months'], true)) {
            $errors[] = "{$path}: {$field['label']} needs a duration from 1 to 3650 hours, days, weeks, or months.";

            return self::invalidValue();
        }

        return ['amount' => $amount, 'unit' => $unit];
    }

    private function normalizeNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = round((float) $value, 6);

        return floor($number) === $number ? (int) $number : $number;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function normalizeSelection(mixed $value, array $field): string|int|float|null
    {
        if (! is_scalar($value)) {
            return null;
        }

        $candidate = trim((string) $value);

        if ($candidate === '' || mb_strlen($candidate) > 255) {
            return null;
        }

        $options = $field['options'] ?? [];

        if ($options === []) {
            return $candidate;
        }

        foreach ($options as $option) {
            $optionValue = (string) ($option['value'] ?? '');

            if (mb_strtolower($candidate) === mb_strtolower($optionValue)) {
                return $option['value'];
            }
        }

        return null;
    }

    private static function invalidValue(): object
    {
        static $invalid;

        return $invalid ??= new class {};
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @param  array<string, array<string, mixed>>  $fieldCatalog
     * @return array{matched: bool, warnings: array<int, string>, evidence: array<int, array<string, mixed>>}
     */
    private function evaluateNode(
        array $node,
        array $context,
        array $fieldCatalog,
        string $scope,
        Carbon $evaluatedAt,
    ): array {
        if (! isset($node['group'])) {
            return $this->evaluateCondition($node, $context, $fieldCatalog, $scope, $evaluatedAt);
        }

        /** @var array<int, array<string, mixed>> $children */
        $children = $node['rules'] ?? [];
        $results = array_map(
            fn (array $child): array => $this->evaluateNode($child, $context, $fieldCatalog, $scope, $evaluatedAt),
            $children,
        );
        $matches = array_column($results, 'matched');

        return [
            'matched' => $this->groupMatches((string) ($node['group'] ?? 'all'), $matches),
            'warnings' => array_values(array_unique(array_merge(...array_map(
                static fn (array $result): array => $result['warnings'],
                $results,
            )))),
            'evidence' => array_merge(...array_map(
                static fn (array $result): array => $result['evidence'],
                $results,
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @param  array<string, array<string, mixed>>  $fieldCatalog
     * @return array{matched: bool, warnings: array<int, string>, evidence: array<int, array<string, mixed>>}
     */
    private function evaluateCondition(
        array $node,
        array $context,
        array $fieldCatalog,
        string $scope,
        Carbon $evaluatedAt,
    ): array {
        $fieldKey = (string) $node['field'];
        $field = $fieldCatalog[$fieldKey];
        $operator = (string) $node['operator'];
        $available = array_key_exists($fieldKey, $context) && $context[$fieldKey] !== null;
        $actual = $available ? $context[$fieldKey] : null;
        $warning = null;

        if ($operator === 'is_present') {
            $matched = $available;
        } elseif ($operator === 'is_missing') {
            $matched = ! $available;
        } elseif (! $available) {
            $matched = false;
            $warning = "{$field['label']} was unavailable; this condition did not match.";
        } else {
            [$matched, $warning] = $this->compare($actual, $operator, $node['value'] ?? null, $field, $evaluatedAt);
        }

        $sentence = $this->conditionSentence($node, $field);

        return [
            'matched' => $matched,
            'warnings' => $warning !== null ? [$warning] : [],
            'evidence' => [[
                'condition_id' => $node['id'] ?? null,
                'scope' => $scope,
                'field' => $fieldKey,
                'field_label' => $field['label'],
                'condition' => $sentence,
                'operator' => $operator,
                'operator_label' => $this->operatorLabel($operator),
                'observed' => $available ? $actual : null,
                'observed_display' => $available ? $this->displayValue($actual, $field) : 'Unavailable',
                'expected' => $node['value'] ?? null,
                'expected_display' => $this->expectedDisplay($operator, $node['value'] ?? null, $field),
                'matched' => $matched,
                'member_safe' => (bool) ($field['member_safe'] ?? false),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{bool, string|null}
     */
    private function compare(mixed $actual, string $operator, mixed $expected, array $field, Carbon $evaluatedAt): array
    {
        $type = (string) ($field['type'] ?? '');

        if ($type === 'number') {
            if (! is_numeric($actual)) {
                return [false, "{$field['label']} was not numeric; this condition did not match."];
            }

            $actualNumber = (float) $actual;
            $matched = match ($operator) {
                'gt' => $actualNumber > (float) $expected,
                'gte' => $actualNumber >= (float) $expected,
                'lt' => $actualNumber < (float) $expected,
                'lte' => $actualNumber <= (float) $expected,
                'eq' => abs($actualNumber - (float) $expected) < 0.000001,
                'neq' => abs($actualNumber - (float) $expected) >= 0.000001,
                'between' => $actualNumber >= (float) $expected['min'] && $actualNumber <= (float) $expected['max'],
                'not_between' => $actualNumber < (float) $expected['min'] || $actualNumber > (float) $expected['max'],
                'multiple_of' => abs(fmod($actualNumber, abs((float) $expected))) < 0.000001,
                'not_multiple_of' => abs(fmod($actualNumber, abs((float) $expected))) >= 0.000001,
                default => false,
            };

            return [$matched, null];
        }

        if ($type === 'boolean') {
            $actualBoolean = filter_var($actual, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($actualBoolean === null) {
                return [false, "{$field['label']} was not a yes/no value; this condition did not match."];
            }

            return [match ($operator) {
                'is_true' => $actualBoolean,
                'is_false' => ! $actualBoolean,
                default => false,
            }, null];
        }

        if ($type === 'collection') {
            if (! is_array($actual)) {
                return [false, "{$field['label']} was not a list; this condition did not match."];
            }

            $actualValues = array_map(static fn (mixed $item): string => mb_strtolower((string) $item), $actual);
            $expectedValues = array_map(static fn (mixed $item): string => mb_strtolower((string) $item), (array) $expected);

            return [match ($operator) {
                'contains_all' => count(array_diff($expectedValues, $actualValues)) === 0,
                'contains_any' => count(array_intersect($expectedValues, $actualValues)) > 0,
                'contains_none' => count(array_intersect($expectedValues, $actualValues)) === 0,
                default => false,
            }, null];
        }

        if ($type === 'datetime') {
            try {
                $actualDate = Carbon::parse($actual);
            } catch (Throwable) {
                return [false, "{$field['label']} was not a valid date; this condition did not match."];
            }

            if (in_array($operator, ['before', 'after'], true)) {
                $expectedDate = Carbon::parse((string) $expected);

                return [$operator === 'before' ? $actualDate->lt($expectedDate) : $actualDate->gt($expectedDate), null];
            }

            $threshold = $evaluatedAt->copy()->sub((int) $expected['amount'], (string) $expected['unit']);

            return [$operator === 'older_than' ? $actualDate->lt($threshold) : $actualDate->gt($threshold), null];
        }

        $actualValue = mb_strtolower(trim((string) $actual));
        $expectedValues = array_map(
            static fn (mixed $item): string => mb_strtolower(trim((string) $item)),
            is_array($expected) ? $expected : [$expected],
        );

        return [match ($operator) {
            'eq' => $actualValue === $expectedValues[0],
            'neq' => $actualValue !== $expectedValues[0],
            'in' => in_array($actualValue, $expectedValues, true),
            'not_in' => ! in_array($actualValue, $expectedValues, true),
            default => false,
        }, null];
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function displayValue(mixed $value, array $field): string
    {
        if ($value === null) {
            return 'Unavailable';
        }

        if (is_array($value)) {
            if (isset($value['amount'], $value['unit'])) {
                return $value['amount'].' '.$value['unit'];
            }

            return implode(', ', array_map(fn (mixed $item): string => $this->displayValue($item, $field), $value));
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $options = $field['options'] ?? [];

        foreach ($options as $option) {
            if ((string) ($option['value'] ?? '') === (string) $value) {
                return (string) ($option['label'] ?? $value);
            }
        }

        if (is_numeric($value)) {
            $number = rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
            $unit = (string) ($field['unit'] ?? '');

            return match ($unit) {
                'currency' => '$'.$number,
                'percent' => $number.'%',
                default => $unit !== '' ? $number.' '.$unit : $number,
            };
        }

        try {
            if (($field['type'] ?? null) === 'datetime') {
                return Carbon::parse((string) $value)->utc()->format('M j, Y H:i \U\T\C');
            }
        } catch (Throwable) {
            // The normalized value is still more useful than hiding it.
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function expectedDisplay(string $operator, mixed $value, array $field): string
    {
        return match ($operator) {
            'is_true' => 'Yes',
            'is_false' => 'No',
            'is_present' => 'Available',
            'is_missing' => 'Missing',
            'between', 'not_between' => $this->displayValue($value['min'] ?? null, $field).' to '.$this->displayValue($value['max'] ?? null, $field),
            default => $this->displayValue($value, $field),
        };
    }

    private function operatorLabel(string $operator): string
    {
        return match ($operator) {
            'gt' => 'Greater than',
            'gte' => 'At least',
            'lt' => 'Less than',
            'lte' => 'At most',
            'eq' => 'Equals',
            'neq' => 'Does not equal',
            'between' => 'Between',
            'not_between' => 'Not between',
            'multiple_of' => 'Is a multiple of',
            'not_multiple_of' => 'Is not a multiple of',
            'in' => 'Is one of',
            'not_in' => 'Is not one of',
            'contains_all' => 'Contains all',
            'contains_any' => 'Contains any',
            'contains_none' => 'Contains none',
            'is_true' => 'Is yes',
            'is_false' => 'Is no',
            'is_present' => 'Is available',
            'is_missing' => 'Is missing',
            'before' => 'Before',
            'after' => 'After',
            'older_than' => 'Older than',
            'newer_than' => 'Newer than',
            default => $operator,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function canonicalizeNode(array $node): array
    {
        if (isset($node['group'])) {
            $children = array_map(
                fn (array $child): array => $this->canonicalizeNode($child),
                is_array($node['rules'] ?? null) ? $node['rules'] : [],
            );
            usort($children, static fn (array $left, array $right): int => json_encode($left) <=> json_encode($right));

            return [
                'group' => $node['group'],
                'rules' => $children,
            ];
        }

        $value = $node['value'] ?? null;

        if (is_array($value) && array_is_list($value)) {
            sort($value, SORT_STRING);
        }

        return [
            'field' => $node['field'] ?? null,
            'operator' => $node['operator'] ?? null,
            'value' => $value,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  &$fields
     */
    private function collectFields(array $node, array &$fields): void
    {
        if (isset($node['field'])) {
            $fields[] = (string) $node['field'];

            return;
        }

        foreach (is_array($node['rules'] ?? null) ? $node['rules'] : [] as $child) {
            if (is_array($child)) {
                $this->collectFields($child, $fields);
            }
        }
    }
}

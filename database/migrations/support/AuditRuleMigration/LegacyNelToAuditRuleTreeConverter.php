<?php

namespace Database\Migrations\Support\AuditRuleMigration;

use Database\Migrations\Support\AuditRuleMigration\Ast\BinaryOpNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\ExpressionNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\FunctionCallNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\IdentifierNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\LiteralNode;
use Database\Migrations\Support\AuditRuleMigration\Ast\UnaryOpNode;
use Throwable;

final class LegacyNelToAuditRuleTreeConverter
{
    private const MAX_DEPTH = 5;

    private const MAX_NODES = 100;

    private const MAX_CHILDREN = 25;

    /** @var list<string> */
    private const COMPARISON_OPERATORS = ['<', '<=', '>', '>=', '==', '!='];

    public function __construct(
        private readonly Parser $parser = new Parser,
        private readonly LegacyFieldCatalog $fields = new LegacyFieldCatalog,
    ) {}

    public function convert(string $expression, string $targetType): ConversionResult
    {
        if (! in_array($targetType, ['nation', 'city'], true)) {
            return ConversionResult::unsupported(
                'invalid_target',
                'The legacy rule has an unsupported audit target.',
                ['target_type' => $targetType],
            );
        }

        if (trim($expression) === '') {
            return ConversionResult::unsupported('empty_expression', 'The legacy expression is empty.');
        }

        try {
            $converted = $this->convertNode($this->parser->parse($expression), $targetType);
            if (isset($converted['group'])) {
                unset($converted['id']);
                $criteria = $converted;
            } else {
                $criteria = ['group' => 'all', 'rules' => [$converted]];
            }

            $this->assertStructuralLimits($criteria);

            return ConversionResult::success([
                'schema_version' => 1,
                'criteria' => $criteria,
                'exceptions' => [
                    'group' => 'any',
                    'rules' => [],
                ],
            ]);
        } catch (SyntaxException $exception) {
            return ConversionResult::unsupported(
                'syntax_error',
                'The legacy expression is malformed.',
                ['detail' => $exception->getMessage()],
            );
        } catch (UnsupportedExpressionException $exception) {
            return ConversionResult::unsupported(
                $exception->reasonCode,
                $exception->getMessage(),
                $exception->context,
            );
        } catch (Throwable $exception) {
            return ConversionResult::unsupported(
                'conversion_error',
                'The legacy expression could not be converted safely.',
                ['exception' => $exception::class],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function convertNode(ExpressionNode $node, string $targetType, bool $negated = false): array
    {
        if ($node instanceof UnaryOpNode) {
            if ($node->operator === '!') {
                return $this->convertNode($node->operand, $targetType, ! $negated);
            }

            throw new UnsupportedExpressionException(
                'unsupported_boolean_shape',
                'Unary arithmetic cannot form an audit condition.',
                ['operator' => $node->operator],
            );
        }

        if ($node instanceof BinaryOpNode && in_array($node->operator, ['&&', '||'], true)) {
            return $this->convertLogicalNode($node, $targetType, $negated);
        }

        if ($node instanceof BinaryOpNode && in_array($node->operator, self::COMPARISON_OPERATORS, true)) {
            $operator = $negated ? $this->invertComparison($node->operator) : $node->operator;

            return $this->convertComparison($node->left, $operator, $node->right, $targetType);
        }

        if ($node instanceof FunctionCallNode) {
            if ($node->name === 'city.improvements_count') {
                throw new UnsupportedExpressionException(
                    'non_boolean_expression',
                    'A bare improvement count cannot be represented as an audit condition.',
                    ['helper' => $node->name],
                );
            }

            return $this->convertBooleanFunction($node, $targetType, $negated);
        }

        if ($node instanceof IdentifierNode) {
            $descriptor = $this->descriptorForPath($node->path(), $targetType);

            if ($descriptor['type'] !== 'boolean') {
                throw new UnsupportedExpressionException(
                    'non_boolean_expression',
                    'A bare non-Boolean field cannot be represented as an audit condition.',
                    ['field' => $node->path()],
                );
            }

            return $this->condition($descriptor['field'], $negated ? 'is_false' : 'is_true', null, false);
        }

        if ($node instanceof LiteralNode) {
            throw new UnsupportedExpressionException(
                is_bool($node->value) ? 'constant_boolean' : 'non_boolean_expression',
                'A standalone literal cannot be represented as a guided audit condition.',
                ['literal_type' => get_debug_type($node->value)],
            );
        }

        throw new UnsupportedExpressionException(
            'unsupported_boolean_shape',
            'The expression does not reduce to supported Boolean conditions.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function convertLogicalNode(BinaryOpNode $node, string $targetType, bool $negated): array
    {
        $sourceOperator = $node->operator;
        $groupOperator = match ([$sourceOperator, $negated]) {
            ['&&', false], ['||', true] => 'all',
            ['||', false], ['&&', true] => 'any',
        };
        $operands = $this->flattenLogicalOperands($node, $sourceOperator);
        $rules = array_map(
            fn (ExpressionNode $operand): array => $this->convertNode($operand, $targetType, $negated),
            $operands,
        );

        return $this->groupRules($groupOperator, $rules);
    }

    /**
     * @return list<ExpressionNode>
     */
    private function flattenLogicalOperands(ExpressionNode $node, string $operator): array
    {
        if ($node instanceof BinaryOpNode && $node->operator === $operator) {
            return [
                ...$this->flattenLogicalOperands($node->left, $operator),
                ...$this->flattenLogicalOperands($node->right, $operator),
            ];
        }

        return [$node];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function groupRules(string $group, array $rules): array
    {
        if (count($rules) <= self::MAX_CHILDREN) {
            return [
                'id' => $this->uuid(),
                'group' => $group,
                'rules' => $rules,
            ];
        }

        $childGroups = array_map(
            fn (array $chunk): array => $this->groupRules($group, $chunk),
            array_chunk($rules, self::MAX_CHILDREN),
        );

        return $this->groupRules($group, $childGroups);
    }

    /**
     * @return array<string, mixed>
     */
    private function convertComparison(
        ExpressionNode $left,
        string $operator,
        ExpressionNode $right,
        string $targetType,
    ): array {
        $booleanComparison = $this->convertBooleanExpressionComparison($left, $operator, $right, $targetType);

        if ($booleanComparison !== null) {
            return $booleanComparison;
        }

        $discordComparison = $this->convertDiscordPresenceComparison($left, $operator, $right, $targetType);

        if ($discordComparison !== null) {
            return $discordComparison;
        }

        $moduloComparison = $this->convertModuloComparison($left, $operator, $right, $targetType);

        if ($moduloComparison !== null) {
            return $moduloComparison;
        }

        $capacityComparison = $this->convertCapacityExceededComparison($left, $operator, $right, $targetType);

        if ($capacityComparison !== null) {
            return $capacityComparison;
        }

        $perCityComparison = $this->convertPerCityMultiplicationComparison($left, $operator, $right, $targetType);

        if ($perCityComparison !== null) {
            return $perCityComparison;
        }

        $leftOperand = $this->convertValueOperand($left, $targetType);
        $rightOperand = $this->convertValueOperand($right, $targetType);

        if ($leftOperand['kind'] === 'literal' && $rightOperand['kind'] === 'field') {
            [$leftOperand, $rightOperand] = [$rightOperand, $leftOperand];
            $operator = $this->reverseComparison($operator);
        }

        if ($leftOperand['kind'] !== 'field' || $rightOperand['kind'] !== 'literal') {
            throw new UnsupportedExpressionException(
                'field_to_field_comparison',
                'Comparisons between calculated fields cannot be converted safely.',
                ['operator' => $operator],
            );
        }

        return $this->fieldLiteralCondition($leftOperand, $operator, $rightOperand['value']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertBooleanExpressionComparison(
        ExpressionNode $left,
        string $operator,
        ExpressionNode $right,
        string $targetType,
    ): ?array {
        if (! in_array($operator, ['==', '!='], true)) {
            return null;
        }

        $leftBoolean = $this->literalBoolean($left);
        $rightBoolean = $this->literalBoolean($right);

        if ($leftBoolean !== null && $rightBoolean === null) {
            return $this->convertBooleanNodeComparedToLiteral($right, $leftBoolean, $operator, $targetType);
        }

        if ($rightBoolean !== null && $leftBoolean === null) {
            return $this->convertBooleanNodeComparedToLiteral($left, $rightBoolean, $operator, $targetType);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function convertBooleanNodeComparedToLiteral(
        ExpressionNode $node,
        bool $literal,
        string $operator,
        string $targetType,
    ): array {
        $negated = ($operator === '==' && ! $literal) || ($operator === '!=' && $literal);

        while ($node instanceof UnaryOpNode && $node->operator === '!') {
            $negated = ! $negated;
            $node = $node->operand;
        }

        if ($node instanceof FunctionCallNode) {
            return $this->convertBooleanFunction($node, $targetType, $negated);
        }

        if ($node instanceof IdentifierNode) {
            $descriptor = $this->descriptorForPath($node->path(), $targetType);

            if ($descriptor['type'] !== 'boolean') {
                throw new UnsupportedExpressionException(
                    'invalid_boolean_comparison',
                    'Only Boolean fields can be compared with true or false.',
                    ['field' => $node->path()],
                );
            }

            return $this->condition($descriptor['field'], $negated ? 'is_false' : 'is_true', null, false);
        }

        throw new UnsupportedExpressionException(
            'invalid_boolean_comparison',
            'The Boolean comparison uses an unsupported expression.',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertDiscordPresenceComparison(
        ExpressionNode $left,
        string $operator,
        ExpressionNode $right,
        string $targetType,
    ): ?array {
        $fieldOnLeft = $left instanceof IdentifierNode && $left->path() === 'nation.account_discord_id';
        $fieldOnRight = $right instanceof IdentifierNode && $right->path() === 'nation.account_discord_id';

        if (! $fieldOnLeft && ! $fieldOnRight) {
            return null;
        }

        if ($targetType !== 'nation') {
            throw new UnsupportedExpressionException(
                'target_field_mismatch',
                'Discord account linkage is unavailable for city audit targets.',
                ['field' => 'nation.account_discord_id'],
            );
        }

        $literal = $fieldOnLeft ? $this->literalValue($right) : $this->literalValue($left);

        if (! $literal['is_literal'] || $literal['value'] !== null || ! in_array($operator, ['==', '!='], true)) {
            throw new UnsupportedExpressionException(
                'unsupported_discord_identifier_comparison',
                'Discord identifiers can only be converted from null-presence comparisons.',
            );
        }

        return $this->condition(
            'nation.discord_account_linked',
            $operator === '!=' ? 'is_true' : 'is_false',
            null,
            false,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertModuloComparison(
        ExpressionNode $left,
        string $operator,
        ExpressionNode $right,
        string $targetType,
    ): ?array {
        $modulo = null;
        $zero = null;

        if ($left instanceof BinaryOpNode && $left->operator === '%') {
            $modulo = $left;
            $zero = $this->literalValue($right);
        } elseif ($right instanceof BinaryOpNode && $right->operator === '%') {
            $modulo = $right;
            $zero = $this->literalValue($left);
        }

        if ($modulo === null) {
            return null;
        }

        if (! in_array($operator, ['==', '!='], true) || ! $zero['is_literal'] || $zero['value'] !== 0) {
            throw new UnsupportedExpressionException(
                'unsupported_modulo_comparison',
                'Modulo expressions are only supported when compared with zero using == or !=.',
            );
        }

        $field = $this->convertValueOperand($modulo->left, $targetType);
        $divisor = $this->literalValue($modulo->right);

        if ($field['kind'] !== 'field' || $field['type'] !== 'number') {
            throw new UnsupportedExpressionException(
                'unsupported_modulo_operand',
                'Modulo requires a supported numeric field on the left.',
            );
        }

        if (! $divisor['is_literal'] || ! is_int($divisor['value']) || $divisor['value'] <= 0) {
            throw new UnsupportedExpressionException(
                'invalid_modulo_divisor',
                'Modulo conversion requires a positive whole-number divisor.',
            );
        }

        return $this->condition(
            $field['field'],
            $operator === '==' ? 'multiple_of' : 'not_multiple_of',
            $divisor['value'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertCapacityExceededComparison(
        ExpressionNode $left,
        string $operator,
        ExpressionNode $right,
        string $targetType,
    ): ?array {
        if ($targetType !== 'city') {
            return null;
        }

        $leftSemantic = $this->semanticField($left, $targetType);
        $rightSemantic = $this->semanticField($right, $targetType);
        $countOnLeft = $leftSemantic === 'city.improvement_count';
        $capacityOnRight = $rightSemantic === 'city.improvement_capacity';
        $capacityOnLeft = $leftSemantic === 'city.improvement_capacity';
        $countOnRight = $rightSemantic === 'city.improvement_count';

        if (! (($countOnLeft && $capacityOnRight) || ($capacityOnLeft && $countOnRight))) {
            return null;
        }

        if ($capacityOnLeft) {
            $operator = $this->reverseComparison($operator);
        }

        return match ($operator) {
            '>' => $this->condition('city.improvement_capacity_exceeded', 'is_true', null, false),
            '<=' => $this->condition('city.improvement_capacity_exceeded', 'is_false', null, false),
            default => throw new UnsupportedExpressionException(
                'unsupported_capacity_comparison',
                'Only improvement count above capacity, or not above capacity, has an exact guided-rule equivalent.',
                ['operator' => $operator],
            ),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertPerCityMultiplicationComparison(
        ExpressionNode $left,
        string $operator,
        ExpressionNode $right,
        string $targetType,
    ): ?array {
        $leftMilitary = $this->militaryIdentifier($left, $targetType);
        $rightMilitary = $this->militaryIdentifier($right, $targetType);
        $rightRate = $this->cityCountMultiplier($right);
        $leftRate = $this->cityCountMultiplier($left);

        if ($leftMilitary !== null && $rightRate !== null) {
            return $this->condition($leftMilitary, $this->numericOperator($operator), $rightRate);
        }

        if ($rightMilitary !== null && $leftRate !== null) {
            return $this->condition(
                $rightMilitary,
                $this->numericOperator($this->reverseComparison($operator)),
                $leftRate,
            );
        }

        return null;
    }

    /**
     * @return array{kind: 'field', field: string, type: 'number'|'text'|'boolean'|'datetime'}|array{kind: 'literal', value: mixed}
     */
    private function convertValueOperand(ExpressionNode $node, string $targetType): array
    {
        $literal = $this->literalValue($node);

        if ($literal['is_literal']) {
            return ['kind' => 'literal', 'value' => $literal['value']];
        }

        if ($node instanceof IdentifierNode) {
            $descriptor = $this->descriptorForPath($node->path(), $targetType);

            return ['kind' => 'field', ...$descriptor];
        }

        $semanticField = $this->semanticField($node, $targetType);

        if ($semanticField !== null) {
            return ['kind' => 'field', 'field' => $semanticField, 'type' => 'number'];
        }

        if ($node instanceof FunctionCallNode) {
            throw new UnsupportedExpressionException(
                'unknown_helper',
                'The legacy expression uses an unsupported helper.',
                ['helper' => $node->name],
            );
        }

        if ($node instanceof BinaryOpNode && in_array($node->operator, ['+', '-', '*', '/', '%'], true)) {
            throw new UnsupportedExpressionException(
                'arbitrary_arithmetic',
                'Arbitrary arithmetic cannot be converted to the guided rule system.',
                ['operator' => $node->operator],
            );
        }

        throw new UnsupportedExpressionException(
            'unsupported_operand',
            'The comparison contains an unsupported operand.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldLiteralCondition(array $field, string $operator, mixed $value): array
    {
        if ($value === null) {
            if (! in_array($operator, ['==', '!='], true)) {
                throw new UnsupportedExpressionException(
                    'invalid_presence_comparison',
                    'Null can only be compared with == or !=.',
                    ['field' => $field['field']],
                );
            }

            return $this->condition(
                $field['field'],
                $operator === '==' ? 'is_missing' : 'is_present',
                null,
                false,
            );
        }

        if ($field['type'] === 'number') {
            if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                throw new UnsupportedExpressionException(
                    'invalid_literal_type',
                    'A numeric field is compared with a non-numeric value.',
                    ['field' => $field['field'], 'literal_type' => get_debug_type($value)],
                );
            }

            $number = is_string($value) ? (float) $value : $value;

            return $this->condition($field['field'], $this->numericOperator($operator), $number);
        }

        if ($field['type'] === 'text') {
            if (! is_string($value) || ! in_array($operator, ['==', '!='], true)) {
                throw new UnsupportedExpressionException(
                    'invalid_text_comparison',
                    'Text fields only support == or != with a string literal.',
                    ['field' => $field['field'], 'operator' => $operator],
                );
            }

            return $this->condition($field['field'], $operator === '==' ? 'eq' : 'neq', $value);
        }

        if ($field['type'] === 'boolean') {
            if (! is_bool($value) || ! in_array($operator, ['==', '!='], true)) {
                throw new UnsupportedExpressionException(
                    'invalid_boolean_comparison',
                    'Boolean fields only support == or != with true or false.',
                    ['field' => $field['field'], 'operator' => $operator],
                );
            }

            $expected = $operator === '==' ? $value : ! $value;

            return $this->condition($field['field'], $expected ? 'is_true' : 'is_false', null, false);
        }

        if ($field['type'] === 'datetime') {
            return $this->datetimeCondition($field['field'], $operator, $value);
        }

        throw new UnsupportedExpressionException('unknown_field_type', 'The legacy field type is unsupported.');
    }

    /**
     * @return array<string, mixed>
     */
    private function datetimeCondition(string $field, string $operator, mixed $value): array
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new UnsupportedExpressionException(
                'invalid_datetime_literal',
                'Activity timestamps must be fixed Unix timestamps.',
                ['field' => $field],
            );
        }

        $timestamp = (int) $value;

        if ($timestamp < 0) {
            throw new UnsupportedExpressionException(
                'invalid_datetime_literal',
                'Activity timestamps cannot be negative.',
                ['field' => $field],
            );
        }

        [$dateOperator, $adjustment] = match ($operator) {
            '<' => ['before', 0],
            '<=' => ['before', 1],
            '>' => ['after', 0],
            '>=' => ['after', -1],
            default => throw new UnsupportedExpressionException(
                'unsupported_datetime_comparison',
                'Fixed timestamps can only be converted from before/after comparisons.',
                ['operator' => $operator],
            ),
        };

        return $this->condition($field, $dateOperator, gmdate('Y-m-d\TH:i:s\Z', $timestamp + $adjustment));
    }

    /**
     * @return array<string, mixed>
     */
    private function convertBooleanFunction(
        FunctionCallNode $node,
        string $targetType,
        bool $negated,
    ): array {
        if ($node->name !== 'nation.has_project') {
            throw new UnsupportedExpressionException(
                'unknown_helper',
                'The legacy expression uses an unsupported Boolean helper.',
                ['helper' => $node->name],
            );
        }

        if (count($node->arguments) !== 1) {
            throw new UnsupportedExpressionException(
                'invalid_helper_arguments',
                'nation.has_project requires exactly one project name.',
                ['helper' => $node->name],
            );
        }

        $project = $this->literalValue($node->arguments[0]);

        if (! $project['is_literal'] || ! is_string($project['value']) || ! $this->fields->isKnownProject($project['value'])) {
            throw new UnsupportedExpressionException(
                'unknown_project',
                'The project helper references an unknown project.',
                ['project' => is_scalar($project['value']) ? (string) $project['value'] : null],
            );
        }

        return $this->condition(
            'nation.projects',
            $negated ? 'contains_none' : 'contains_any',
            [$project['value']],
        );
    }

    private function semanticField(ExpressionNode $node, string $targetType): ?string
    {
        if ($node instanceof FunctionCallNode && $node->name === 'city.improvements_count') {
            if ($targetType !== 'city') {
                throw new UnsupportedExpressionException(
                    'target_field_mismatch',
                    'City improvement counts are unavailable for nation audit targets.',
                    ['helper' => $node->name],
                );
            }

            if ($node->arguments !== []) {
                throw new UnsupportedExpressionException(
                    'invalid_helper_arguments',
                    'city.improvements_count does not accept arguments.',
                    ['helper' => $node->name],
                );
            }

            return 'city.improvement_count';
        }

        if (! $node instanceof BinaryOpNode || $node->operator !== '/') {
            return null;
        }

        if ($node->left instanceof IdentifierNode && $node->right instanceof IdentifierNode) {
            if ($node->right->path() === 'nation.num_cities') {
                return $this->fields->perCityField($node->left->path(), $targetType);
            }
        }

        if ($targetType === 'city' && $node->left instanceof IdentifierNode && $node->left->path() === 'city.infrastructure') {
            $divisor = $this->literalValue($node->right);

            if ($divisor['is_literal'] && ($divisor['value'] === 50 || $divisor['value'] === 50.0)) {
                return 'city.improvement_capacity';
            }
        }

        return null;
    }

    private function militaryIdentifier(ExpressionNode $node, string $targetType): ?string
    {
        if (! $node instanceof IdentifierNode) {
            return null;
        }

        return $this->fields->perCityField($node->path(), $targetType);
    }

    private function cityCountMultiplier(ExpressionNode $node): int|float|null
    {
        if (! $node instanceof BinaryOpNode || $node->operator !== '*') {
            return null;
        }

        $leftIsCityCount = $node->left instanceof IdentifierNode && $node->left->path() === 'nation.num_cities';
        $rightIsCityCount = $node->right instanceof IdentifierNode && $node->right->path() === 'nation.num_cities';
        $literal = $leftIsCityCount
            ? $this->literalValue($node->right)
            : ($rightIsCityCount ? $this->literalValue($node->left) : ['is_literal' => false, 'value' => null]);

        if (! $literal['is_literal'] || (! is_int($literal['value']) && ! is_float($literal['value']))) {
            return null;
        }

        return $literal['value'];
    }

    /**
     * @return array{field: string, type: 'number'|'text'|'boolean'|'datetime'}
     */
    private function descriptorForPath(string $path, string $targetType): array
    {
        if ($path === 'nation.project_bits') {
            throw new UnsupportedExpressionException(
                'raw_project_bitmask',
                'Raw project bitmask comparisons cannot be converted; use project ownership conditions.',
                ['field' => $path],
            );
        }

        if ($path === 'nation.account_discord_id') {
            throw new UnsupportedExpressionException(
                'unsupported_discord_identifier_comparison',
                'Discord identifiers can only be converted from null-presence comparisons.',
                ['field' => $path],
            );
        }

        $descriptor = $this->fields->describe($path, $targetType);

        if ($descriptor !== null) {
            return $descriptor;
        }

        throw new UnsupportedExpressionException(
            $this->fields->isKnownLegacyPath($path) ? 'target_field_mismatch' : 'unknown_field',
            $this->fields->isKnownLegacyPath($path)
                ? 'The field is not available for this audit target.'
                : 'The expression references an unknown audit field.',
            ['field' => $path, 'target_type' => $targetType],
        );
    }

    private function numericOperator(string $operator): string
    {
        return match ($operator) {
            '<' => 'lt',
            '<=' => 'lte',
            '>' => 'gt',
            '>=' => 'gte',
            '==' => 'eq',
            '!=' => 'neq',
            default => throw new UnsupportedExpressionException(
                'unsupported_numeric_operator',
                'The numeric comparison operator is unsupported.',
                ['operator' => $operator],
            ),
        };
    }

    private function reverseComparison(string $operator): string
    {
        return match ($operator) {
            '<' => '>',
            '<=' => '>=',
            '>' => '<',
            '>=' => '<=',
            '==', '!=' => $operator,
            default => throw new UnsupportedExpressionException(
                'unsupported_comparison_operator',
                'The comparison operator is unsupported.',
                ['operator' => $operator],
            ),
        };
    }

    private function invertComparison(string $operator): string
    {
        return match ($operator) {
            '<' => '>=',
            '<=' => '>',
            '>' => '<=',
            '>=' => '<',
            '==' => '!=',
            '!=' => '==',
            default => throw new UnsupportedExpressionException(
                'unsupported_comparison_operator',
                'The comparison operator is unsupported.',
                ['operator' => $operator],
            ),
        };
    }

    private function literalBoolean(ExpressionNode $node): ?bool
    {
        return $node instanceof LiteralNode && is_bool($node->value) ? $node->value : null;
    }

    /**
     * @return array{is_literal: bool, value: mixed}
     */
    private function literalValue(ExpressionNode $node): array
    {
        if ($node instanceof LiteralNode) {
            return ['is_literal' => true, 'value' => $node->value];
        }

        if ($node instanceof UnaryOpNode && $node->operator === '-' && $node->operand instanceof LiteralNode) {
            if (is_int($node->operand->value) || is_float($node->operand->value)) {
                return ['is_literal' => true, 'value' => -$node->operand->value];
            }
        }

        return ['is_literal' => false, 'value' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function condition(string $field, string $operator, mixed $value, bool $includeValue = true): array
    {
        $condition = [
            'id' => $this->uuid(),
            'field' => $field,
            'operator' => $operator,
        ];

        if ($includeValue) {
            $condition['value'] = $value;
        }

        return $condition;
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function assertStructuralLimits(array $criteria): void
    {
        $nodeCount = 0;
        $this->inspectGroup($criteria, 1, $nodeCount);

        if ($nodeCount > self::MAX_NODES) {
            throw new UnsupportedExpressionException(
                'structural_limit_exceeded',
                'The converted rule exceeds the 100-node safety limit.',
                ['node_count' => $nodeCount],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function inspectGroup(array $group, int $depth, int &$nodeCount): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new UnsupportedExpressionException(
                'structural_limit_exceeded',
                'The converted rule exceeds the maximum nesting depth.',
                ['depth' => $depth],
            );
        }

        $rules = $group['rules'] ?? [];

        if (! is_array($rules) || count($rules) > self::MAX_CHILDREN) {
            throw new UnsupportedExpressionException(
                'structural_limit_exceeded',
                'A converted group exceeds the 25-child safety limit.',
                ['children' => is_array($rules) ? count($rules) : null],
            );
        }

        $nodeCount++;

        foreach ($rules as $rule) {
            $nodeCount++;

            if (is_array($rule) && isset($rule['group'])) {
                $nodeCount--;
                $this->inspectGroup($rule, $depth + 1, $nodeCount);
            }
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}

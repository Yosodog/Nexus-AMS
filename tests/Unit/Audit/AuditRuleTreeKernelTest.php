<?php

namespace Tests\Unit\Audit;

use App\Enums\AuditTargetType;
use App\Services\Audit\AuditFieldRegistry;
use App\Services\Audit\AuditRuleDefinitionService;
use App\Services\Rules\RuleTreeKernel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditRuleTreeKernelTest extends TestCase
{
    private RuleTreeKernel $kernel;

    private AuditFieldRegistry $fields;

    private AuditRuleDefinitionService $definitions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kernel = new RuleTreeKernel;
        $this->fields = new AuditFieldRegistry;
        $this->definitions = new AuditRuleDefinitionService($this->kernel, $this->fields);
    }

    public function test_it_normalizes_the_versioned_audit_definition_contract(): void
    {
        $definition = $this->definition([
            $this->condition(1, 'nation.aircraft_per_city', 'lt', '50.5000004'),
            $this->condition(2, 'nation.color', 'eq', 'blue'),
            $this->condition(3, 'nation.discord_account_linked', 'is_false', 'ignored'),
        ]);

        $normalized = $this->definitions->normalize($definition, AuditTargetType::Nation);

        $this->assertSame(1, $normalized['schema_version']);
        $this->assertSame('all', $normalized['criteria']['group']);
        $this->assertSame([], $normalized['exceptions']['rules']);
        $this->assertArrayNotHasKey('id', $normalized['criteria']);
        $this->assertSame(50.5, $normalized['criteria']['rules'][0]['value']);
        $this->assertSame('BLUE', $normalized['criteria']['rules'][1]['value']);
        $this->assertNull($normalized['criteria']['rules'][2]['value']);
    }

    public function test_empty_root_groups_are_valid_for_disabled_drafts(): void
    {
        $definition = $this->fields->emptyDefinition();

        $this->assertSame($definition, $this->definitions->normalize($definition, AuditTargetType::Nation));
        $this->assertFalse($this->definitions->hasCriteria($definition));
    }

    #[DataProvider('invalidContractProvider')]
    public function test_it_rejects_invalid_top_level_contracts(mixed $definition, string $expectedError): void
    {
        $inspection = $this->definitions->inspect($definition, AuditTargetType::Nation);

        $this->assertNull($inspection['normalized']);
        $this->assertStringContainsString($expectedError, implode(' ', $inspection['errors']));
    }

    public static function invalidContractProvider(): iterable
    {
        yield 'not an object' => ['nation.score > 1', 'must be an object'];
        yield 'unsupported schema' => [[
            'schema_version' => 2,
            'criteria' => ['group' => 'all', 'rules' => []],
            'exceptions' => ['group' => 'any', 'rules' => []],
        ], 'schema version 1'];
        yield 'condition at criteria root' => [[
            'schema_version' => 1,
            'criteria' => self::staticCondition(1, 'nation.score', 'gt', 1),
            'exceptions' => ['group' => 'any', 'rules' => []],
        ], 'Alert conditions must begin'];
        yield 'missing exception tree' => [[
            'schema_version' => 1,
            'criteria' => ['group' => 'all', 'rules' => []],
        ], 'exceptions must be a group or condition'];
    }

    public function test_it_rejects_unknown_keys_at_every_tree_level(): void
    {
        $definition = $this->definition([
            [
                ...$this->condition(1, 'nation.score', 'gt', 10),
                'formula' => 'nation.score > 10',
            ],
        ]);
        $definition['legacy'] = true;
        $definition['criteria']['debug'] = true;

        $inspection = $this->definitions->inspect($definition, AuditTargetType::Nation);
        $errors = implode(' ', $inspection['errors']);

        $this->assertStringContainsString('unsupported keys: legacy', $errors);
        $this->assertStringContainsString('criteria contains unsupported keys: debug', $errors);
        $this->assertStringContainsString('criteria.rules.0 contains unsupported keys: formula', $errors);
    }

    public function test_it_rejects_unknown_fields_and_operators(): void
    {
        $unknownField = $this->definitions->inspect(
            $this->definition([$this->condition(1, 'nation.secret_formula', 'eq', 1)]),
            AuditTargetType::Nation,
        );
        $unknownOperator = $this->definitions->inspect(
            $this->definition([$this->condition(1, 'nation.score', 'contains_any', ['x'])]),
            AuditTargetType::Nation,
        );

        $this->assertStringContainsString('must use a supported field', implode(' ', $unknownField['errors']));
        $this->assertStringContainsString('does not support that comparison', implode(' ', $unknownOperator['errors']));
    }

    public function test_it_rejects_invalid_and_duplicate_node_uuids(): void
    {
        $invalid = $this->definitions->inspect(
            $this->definition([[
                'id' => 'condition-one',
                'field' => 'nation.score',
                'operator' => 'gt',
                'value' => 1,
            ]]),
            AuditTargetType::Nation,
        );
        $duplicate = $this->definitions->inspect(
            $this->definition([
                $this->condition(1, 'nation.score', 'gt', 1),
                $this->condition(1, 'nation.num_cities', 'gte', 5),
            ]),
            AuditTargetType::Nation,
        );

        $this->assertStringContainsString('valid unique ID', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('repeats a condition or group ID', implode(' ', $duplicate['errors']));
    }

    public function test_it_enforces_the_maximum_group_depth(): void
    {
        $node = $this->condition(10, 'nation.score', 'gt', 1);

        for ($id = 9; $id >= 6; $id--) {
            $node = $this->group($id, 'all', [$node]);
        }

        $inspection = $this->definitions->inspect($this->definition([$node]), AuditTargetType::Nation);

        $this->assertStringContainsString('nested at most 5 levels', implode(' ', $inspection['errors']));
    }

    public function test_it_enforces_the_total_node_limit(): void
    {
        $groups = [];
        $conditionId = 10;

        for ($groupId = 1; $groupId <= 4; $groupId++) {
            $conditions = [];

            for ($child = 0; $child < 25; $child++) {
                $conditions[] = $this->condition($conditionId++, 'nation.score', 'gt', $child);
            }

            $groups[] = $this->group($groupId, 'all', $conditions);
        }

        $inspection = $this->definitions->inspect($this->definition($groups), AuditTargetType::Nation);

        $this->assertStringContainsString('at most 100 groups and conditions', implode(' ', $inspection['errors']));
    }

    public function test_it_enforces_the_direct_child_limit(): void
    {
        $conditions = [];

        for ($id = 1; $id <= 26; $id++) {
            $conditions[] = $this->condition($id, 'nation.score', 'gt', $id);
        }

        $inspection = $this->definitions->inspect($this->definition($conditions), AuditTargetType::Nation);

        $this->assertStringContainsString('at most 25 direct children', implode(' ', $inspection['errors']));
    }

    public function test_it_enforces_the_multi_select_value_limit(): void
    {
        $inspection = $this->definitions->inspect(
            $this->definition([$this->condition(1, 'nation.nation_name', 'in', array_map(
                static fn (int $number): string => "Nation {$number}",
                range(1, 51),
            ))]),
            AuditTargetType::Nation,
        );

        $this->assertStringContainsString('between 1 and 50 selections', implode(' ', $inspection['errors']));
    }

    #[DataProvider('invalidTypedValueProvider')]
    public function test_it_rejects_invalid_typed_values(string $field, string $operator, mixed $value, string $expectedError): void
    {
        $inspection = $this->definitions->inspect(
            $this->definition([$this->condition(1, $field, $operator, $value)]),
            AuditTargetType::Nation,
        );

        $this->assertStringContainsString($expectedError, implode(' ', $inspection['errors']));
    }

    public static function invalidTypedValueProvider(): iterable
    {
        yield 'non numeric' => ['nation.score', 'gt', 'many', 'needs a number'];
        yield 'reversed range' => ['nation.score', 'between', ['min' => 10, 'max' => 5], 'minimum cannot be greater'];
        yield 'zero multiple' => ['nation.score', 'multiple_of', 0, 'multiple cannot be zero'];
        yield 'unsupported enum' => ['nation.color', 'eq', 'CHARTREUSE', 'needs a valid value'];
        yield 'invalid date' => ['nation.last_activity', 'before', 'not-a-date', 'valid date and time'];
        yield 'invalid duration' => ['nation.last_activity', 'older_than', ['amount' => 0, 'unit' => 'days'], 'duration from 1 to 3650'];
    }

    public function test_it_normalizes_ranges_dates_durations_and_unique_selections(): void
    {
        $definition = $this->definition([
            $this->condition(1, 'nation.score', 'between', ['min' => '10', 'max' => '20.25']),
            $this->condition(2, 'nation.last_activity', 'after', '2026-08-02 07:30:00-05:00'),
            $this->condition(3, 'nation.last_activity', 'older_than', ['amount' => '7', 'unit' => 'DAYS']),
            $this->condition(4, 'nation.color', 'in', ['blue', 'BLUE', 'RED']),
        ]);

        $normalized = $this->definitions->normalize($definition, AuditTargetType::Nation);
        $rules = $normalized['criteria']['rules'];

        $this->assertSame(['min' => 10, 'max' => 20.25], $rules[0]['value']);
        $this->assertSame('2026-08-02T12:30:00+00:00', $rules[1]['value']);
        $this->assertSame(['amount' => 7, 'unit' => 'days'], $rules[2]['value']);
        $this->assertSame(['BLUE', 'RED'], $rules[3]['value']);
    }

    public function test_it_generates_plain_language_summaries_for_criteria_and_exceptions(): void
    {
        $definition = $this->definition(
            [$this->condition(1, 'nation.aircraft_per_city', 'lt', 50)],
            [$this->condition(2, 'nation.vacation_mode_turns', 'gt', 0)],
        );
        $normalized = $this->definitions->normalize($definition, AuditTargetType::Nation);

        $this->assertSame(
            'Alert when all of: Aircraft per city is less than 50 aircraft / city. Except when any of: Vacation mode turns is greater than 0 turns.',
            $this->definitions->summarize($normalized, AuditTargetType::Nation),
        );
        $this->assertSame(
            'No alert conditions have been added yet.',
            $this->definitions->summarize($this->fields->emptyDefinition(), AuditTargetType::Nation),
        );
    }

    public function test_fingerprints_ignore_node_ids_commutative_order_and_multi_value_order(): void
    {
        $first = $this->definition([
            $this->condition(1, 'nation.color', 'in', ['BLUE', 'RED']),
            $this->condition(2, 'nation.score', 'gte', 100),
        ]);
        $second = $this->definition([
            $this->condition(91, 'nation.score', 'gte', 100),
            $this->condition(92, 'nation.color', 'in', ['RED', 'BLUE']),
        ]);

        $this->assertSame(
            $this->definitions->fingerprint(AuditTargetType::Nation, $first),
            $this->definitions->fingerprint(AuditTargetType::Nation, $second),
        );
        $this->assertNotSame(
            $this->definitions->fingerprint(AuditTargetType::Nation, $first),
            $this->definitions->fingerprint(AuditTargetType::City, $first),
        );

        $second['criteria']['rules'][0]['value'] = 101;

        $this->assertNotSame(
            $this->definitions->fingerprint(AuditTargetType::Nation, $first),
            $this->definitions->fingerprint(AuditTargetType::Nation, $second),
        );
    }

    public function test_referenced_fields_are_unique_and_sorted_across_both_trees(): void
    {
        $definition = $this->definition(
            [
                $this->condition(1, 'nation.score', 'gt', 1),
                $this->condition(2, 'nation.color', 'eq', 'BLUE'),
            ],
            [$this->condition(3, 'nation.score', 'lt', 2)],
        );

        $this->assertSame(['nation.color', 'nation.score'], $this->definitions->referencedFields($definition));
    }

    public function test_normalize_throws_one_actionable_exception_for_invalid_definitions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Score needs a number');

        $this->definitions->normalize(
            $this->definition([$this->condition(1, 'nation.score', 'gt', 'invalid')]),
            AuditTargetType::Nation,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $criteria
     * @param  array<int, array<string, mixed>>  $exceptions
     * @return array<string, mixed>
     */
    private function definition(array $criteria, array $exceptions = [], string $criteriaGroup = 'all'): array
    {
        return [
            'schema_version' => 1,
            'criteria' => [
                'group' => $criteriaGroup,
                'rules' => $criteria,
            ],
            'exceptions' => [
                'group' => 'any',
                'rules' => $exceptions,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function condition(int $id, string $field, string $operator, mixed $value): array
    {
        return self::staticCondition($id, $field, $operator, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function staticCondition(int $id, string $field, string $operator, mixed $value): array
    {
        return [
            'id' => self::uuid($id),
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function group(int $id, string $group, array $rules): array
    {
        return [
            'id' => self::uuid($id),
            'group' => $group,
            'rules' => $rules,
        ];
    }

    private static function uuid(int $id): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $id);
    }
}

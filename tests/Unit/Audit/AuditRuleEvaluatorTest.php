<?php

namespace Tests\Unit\Audit;

use App\Enums\AuditTargetType;
use App\Services\Audit\AuditEvaluationResult;
use App\Services\Audit\AuditFieldRegistry;
use App\Services\Audit\AuditRuleDefinitionService;
use App\Services\Audit\AuditRuleEvaluator;
use App\Services\Rules\RuleTreeKernel;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditRuleEvaluatorTest extends TestCase
{
    private AuditRuleDefinitionService $definitions;

    private AuditRuleEvaluator $evaluator;

    private Carbon $evaluatedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = new RuleTreeKernel;
        $fields = new AuditFieldRegistry;
        $this->definitions = new AuditRuleDefinitionService($kernel, $fields);
        $this->evaluator = new AuditRuleEvaluator($kernel, $fields, $this->definitions);
        $this->evaluatedAt = Carbon::parse('2026-08-02 12:00:00', 'UTC');
        Carbon::setTestNow($this->evaluatedAt);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('numberComparisonProvider')]
    public function test_number_equality_range_and_multiple_operators(
        string $operator,
        mixed $expected,
        int|float|string $actual,
        bool $matched,
    ): void {
        $result = $this->evaluateNation(
            [$this->condition(1, 'nation.score', $operator, $expected)],
            ['nation.score' => $actual],
        );

        $this->assertSame($matched, $result->matched);
        $this->assertSame([], $result->warnings);
        $this->assertSame($matched, $result->evidence[0]['matched']);
    }

    public static function numberComparisonProvider(): iterable
    {
        yield 'greater than matches' => ['gt', 10, 11, true];
        yield 'greater than boundary fails' => ['gt', 10, 10, false];
        yield 'at least includes boundary' => ['gte', 10, '10', true];
        yield 'less than matches' => ['lt', 10, 9.5, true];
        yield 'at most includes boundary' => ['lte', 10, 10, true];
        yield 'numeric equality tolerates representation' => ['eq', 10, '10.0', true];
        yield 'not equal' => ['neq', 10, 10.1, true];
        yield 'between includes lower bound' => ['between', ['min' => 10, 'max' => 20], 10, true];
        yield 'between includes upper bound' => ['between', ['min' => 10, 'max' => 20], 20, true];
        yield 'not between excludes the range' => ['not_between', ['min' => 10, 'max' => 20], 21, true];
        yield 'multiple of' => ['multiple_of', 2.5, 7.5, true];
        yield 'not multiple of' => ['not_multiple_of', 3, 10, true];
    }

    #[DataProvider('enumAndTextProvider')]
    public function test_enum_text_equality_and_membership_operators(
        string $field,
        string $operator,
        mixed $expected,
        mixed $actual,
        bool $matched,
    ): void {
        $result = $this->evaluateNation(
            [$this->condition(1, $field, $operator, $expected)],
            [$field => $actual],
        );

        $this->assertSame($matched, $result->matched);
        $this->assertSame([], $result->warnings);
    }

    public static function enumAndTextProvider(): iterable
    {
        yield 'enum equality is case insensitive' => ['nation.color', 'eq', 'BLUE', 'blue', true];
        yield 'enum inequality' => ['nation.color', 'neq', 'BLUE', 'RED', true];
        yield 'enum in' => ['nation.color', 'in', ['BLUE', 'RED'], 'red', true];
        yield 'enum not in' => ['nation.color', 'not_in', ['BLUE', 'RED'], 'GREEN', true];
        yield 'text equality trims and ignores case' => ['nation.nation_name', 'eq', 'Aurora', ' aurora ', true];
        yield 'text inequality' => ['nation.nation_name', 'neq', 'Aurora', 'Borealis', true];
        yield 'text in' => ['nation.nation_name', 'in', ['Aurora', 'Borealis'], 'borealis', true];
        yield 'text not in' => ['nation.nation_name', 'not_in', ['Aurora', 'Borealis'], 'Cygnus', true];
    }

    public function test_boolean_operators_accept_real_and_serialized_boolean_values(): void
    {
        $yes = $this->evaluateNation(
            [$this->condition(1, 'nation.discord_account_linked', 'is_true', null)],
            ['nation.discord_account_linked' => 'true'],
        );
        $no = $this->evaluateNation(
            [$this->condition(2, 'nation.discord_account_linked', 'is_false', null)],
            ['nation.discord_account_linked' => false],
        );

        $this->assertTrue($yes->matched);
        $this->assertTrue($no->matched);
        $this->assertSame('Yes', $yes->evidence[0]['expected_display']);
        $this->assertSame('No', $no->evidence[0]['expected_display']);
    }

    public function test_invalid_boolean_context_returns_a_warning_and_nonmatch(): void
    {
        $result = $this->evaluateNation(
            [$this->condition(1, 'nation.discord_account_linked', 'is_true', null)],
            ['nation.discord_account_linked' => 'sometimes'],
        );

        $this->assertFalse($result->matched);
        $this->assertSame(
            ['Discord account linked was not a yes/no value; this condition did not match.'],
            $result->warnings,
        );
    }

    #[DataProvider('collectionProvider')]
    public function test_collection_operators(string $operator, array $expected, mixed $actual, bool $matched): void
    {
        $result = $this->evaluateNation(
            [$this->condition(1, 'nation.projects', $operator, $expected)],
            ['nation.projects' => $actual],
        );

        $this->assertSame($matched, $result->matched);
    }

    public static function collectionProvider(): iterable
    {
        yield 'contains all' => [
            'contains_all',
            ['Ironworks', 'Bauxiteworks'],
            ['ironworks', 'Bauxiteworks', 'Arms Stockpile'],
            true,
        ];
        yield 'contains all fails when one is absent' => [
            'contains_all',
            ['Ironworks', 'Bauxiteworks'],
            ['Ironworks'],
            false,
        ];
        yield 'contains any' => [
            'contains_any',
            ['Ironworks', 'Bauxiteworks'],
            ['Bauxiteworks'],
            true,
        ];
        yield 'contains none' => [
            'contains_none',
            ['Ironworks', 'Bauxiteworks'],
            ['Arms Stockpile'],
            true,
        ];
    }

    public function test_non_collection_context_returns_a_warning_and_nonmatch(): void
    {
        $result = $this->evaluateNation(
            [$this->condition(1, 'nation.projects', 'contains_any', ['Ironworks'])],
            ['nation.projects' => 'Ironworks'],
        );

        $this->assertFalse($result->matched);
        $this->assertSame(['Owned projects was not a list; this condition did not match.'], $result->warnings);
    }

    public function test_presence_operators_distinguish_missing_values_from_false_and_zero(): void
    {
        $missing = $this->evaluateNation(
            [$this->condition(1, 'nation.score', 'is_missing', null)],
            [],
        );
        $null = $this->evaluateNation(
            [$this->condition(2, 'nation.score', 'is_missing', null)],
            ['nation.score' => null],
        );
        $zero = $this->evaluateNation(
            [$this->condition(3, 'nation.score', 'is_present', null)],
            ['nation.score' => 0],
        );
        $false = $this->evaluateNation(
            [$this->condition(4, 'nation.discord_account_linked', 'is_present', null)],
            ['nation.discord_account_linked' => false],
        );

        $this->assertTrue($missing->matched);
        $this->assertTrue($null->matched);
        $this->assertTrue($zero->matched);
        $this->assertTrue($false->matched);
        $this->assertSame([], $missing->warnings);
    }

    #[DataProvider('datetimeProvider')]
    public function test_absolute_datetime_and_relative_duration_operators(
        string $operator,
        mixed $expected,
        string $actual,
        bool $matched,
    ): void {
        $result = $this->evaluateNation(
            [$this->condition(1, 'nation.last_activity', $operator, $expected)],
            ['nation.last_activity' => $actual],
        );

        $this->assertSame($matched, $result->matched);
        $this->assertSame([], $result->warnings);
    }

    public static function datetimeProvider(): iterable
    {
        yield 'before absolute date' => ['before', '2026-08-01T00:00:00+00:00', '2026-07-31 23:59:59 UTC', true];
        yield 'after absolute date' => ['after', '2026-08-01T00:00:00+00:00', '2026-08-01 00:00:01 UTC', true];
        yield 'older than duration' => ['older_than', ['amount' => 7, 'unit' => 'days'], '2026-07-20 12:00:00 UTC', true];
        yield 'newer than duration' => ['newer_than', ['amount' => 7, 'unit' => 'days'], '2026-07-30 12:00:00 UTC', true];
        yield 'exact threshold is neither older' => ['older_than', ['amount' => 7, 'unit' => 'days'], '2026-07-26 12:00:00 UTC', false];
    }

    public function test_invalid_datetime_context_returns_a_warning_and_nonmatch(): void
    {
        $result = $this->evaluateNation(
            [$this->condition(1, 'nation.last_activity', 'before', '2026-08-01T00:00:00+00:00')],
            ['nation.last_activity' => 'not-a-date'],
        );

        $this->assertFalse($result->matched);
        $this->assertSame(['Last activity was not a valid date; this condition did not match.'], $result->warnings);
    }

    public function test_nested_all_and_any_groups_use_boolean_group_semantics(): void
    {
        $nestedAny = $this->group(10, 'any', [
            $this->condition(2, 'nation.color', 'eq', 'BLUE'),
            $this->condition(3, 'nation.color', 'eq', 'RED'),
        ]);
        $definition = $this->definition([
            $this->condition(1, 'nation.score', 'gte', 100),
            $nestedAny,
        ]);

        $matches = $this->evaluate($definition, [
            'nation.score' => 150,
            'nation.color' => 'RED',
        ]);
        $failsAll = $this->evaluate($definition, [
            'nation.score' => 99,
            'nation.color' => 'RED',
        ]);
        $failsAny = $this->evaluate($definition, [
            'nation.score' => 150,
            'nation.color' => 'GREEN',
        ]);

        $this->assertTrue($matches->matched);
        $this->assertFalse($failsAll->matched);
        $this->assertFalse($failsAny->matched);
        $this->assertCount(3, $matches->evidence);
    }

    public function test_a_matching_exception_suppresses_an_otherwise_matching_finding(): void
    {
        $definition = $this->definition(
            [$this->condition(1, 'nation.aircraft_per_city', 'lt', 50)],
            [$this->condition(2, 'nation.vacation_mode_turns', 'gt', 0)],
        );

        $suppressed = $this->evaluate($definition, [
            'nation.aircraft_per_city' => 40,
            'nation.vacation_mode_turns' => 5,
        ]);
        $notSuppressed = $this->evaluate($definition, [
            'nation.aircraft_per_city' => 40,
            'nation.vacation_mode_turns' => 0,
        ]);

        $this->assertFalse($suppressed->matched);
        $this->assertTrue($notSuppressed->matched);
        $this->assertSame(['criteria', 'exception'], array_column($suppressed->evidence, 'scope'));
        $this->assertTrue($suppressed->evidence[1]['matched']);
    }

    public function test_missing_comparison_data_is_not_coerced_to_zero_and_yields_a_warning(): void
    {
        $result = $this->evaluateNation(
            [$this->condition(1, 'nation.score', 'lte', 0)],
            [],
        );

        $this->assertFalse($result->matched);
        $this->assertSame(['Score was unavailable; this condition did not match.'], $result->warnings);
        $this->assertNull($result->evidence[0]['observed']);
        $this->assertSame('Unavailable', $result->evidence[0]['observed_display']);
        $this->assertFalse($result->evidence[0]['matched']);
    }

    public function test_duplicate_warnings_are_aggregated_once(): void
    {
        $result = $this->evaluateNation([
            $this->condition(1, 'nation.score', 'gt', 1),
            $this->condition(2, 'nation.score', 'lt', 100),
        ], []);

        $this->assertFalse($result->matched);
        $this->assertSame(['Score was unavailable; this condition did not match.'], $result->warnings);
        $this->assertCount(2, $result->evidence);
    }

    public function test_empty_criteria_never_match_and_produce_no_evidence(): void
    {
        $result = $this->evaluate($this->definition([]), []);

        $this->assertFalse($result->matched);
        $this->assertSame([], $result->warnings);
        $this->assertSame([], $result->evidence);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function test_evidence_contains_member_safe_plain_language_and_formatted_values(): void
    {
        $conditionId = self::uuid(1);
        $result = $this->evaluateNation(
            [[
                'id' => $conditionId,
                'field' => 'nation.aircraft_per_city',
                'operator' => 'lt',
                'value' => 50,
            ]],
            ['nation.aircraft_per_city' => 42],
        );

        $this->assertTrue($result->matched);
        $this->assertSame([
            'condition_id' => $conditionId,
            'scope' => 'criteria',
            'field' => 'nation.aircraft_per_city',
            'field_label' => 'Aircraft per city',
            'condition' => 'Aircraft per city is less than 50 aircraft / city',
            'operator' => 'lt',
            'operator_label' => 'Less than',
            'observed' => 42,
            'observed_display' => '42 aircraft / city',
            'expected' => 50,
            'expected_display' => '50 aircraft / city',
            'matched' => true,
            'member_safe' => true,
        ], $result->evidence[0]);
    }

    public function test_member_safe_details_remove_unsafe_evidence_and_internal_keys(): void
    {
        $details = $this->definitions->memberSafeDetails([
            'rule_revision' => 7,
            'summary' => 'Alert when aircraft per city is below 50.',
            'evaluated_at' => '2026-08-02T12:00:00+00:00',
            'internal_debug' => 'do not expose',
            'evidence' => [
                [
                    'condition_id' => self::uuid(1),
                    'scope' => 'criteria',
                    'field' => 'nation.aircraft_per_city',
                    'field_label' => 'Aircraft per city',
                    'condition' => 'Aircraft per city is less than 50 aircraft / city',
                    'operator' => 'lt',
                    'operator_label' => 'Less than',
                    'observed' => 42,
                    'observed_display' => '42 aircraft / city',
                    'expected' => 50,
                    'expected_display' => '50 aircraft / city',
                    'matched' => true,
                    'member_safe' => true,
                ],
                [
                    'condition' => 'Internal risk score is high',
                    'member_safe' => false,
                    'observed' => 99,
                ],
            ],
        ]);

        $this->assertSame(7, $details['rule_revision']);
        $this->assertSame('Alert when aircraft per city is below 50.', $details['summary']);
        $this->assertSame('2026-08-02T12:00:00+00:00', $details['evaluated_at']);
        $this->assertCount(1, $details['evidence']);
        $this->assertSame([
            'scope',
            'condition',
            'field_label',
            'operator_label',
            'observed',
            'observed_display',
            'expected',
            'expected_display',
            'matched',
        ], array_keys($details['evidence'][0]));
        $this->assertArrayNotHasKey('internal_debug', $details);
        $this->assertArrayNotHasKey('field', $details['evidence'][0]);
        $this->assertArrayNotHasKey('condition_id', $details['evidence'][0]);
        $this->assertArrayNotHasKey('member_safe', $details['evidence'][0]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $criteria
     * @param  array<string, mixed>  $context
     */
    private function evaluateNation(array $criteria, array $context): AuditEvaluationResult
    {
        return $this->evaluate($this->definition($criteria), $context);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $context
     */
    private function evaluate(array $definition, array $context): AuditEvaluationResult
    {
        $normalized = $this->definitions->normalize($definition, AuditTargetType::Nation);

        return $this->evaluator->evaluate(
            AuditTargetType::Nation,
            $normalized,
            $context,
            $this->evaluatedAt,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $criteria
     * @param  array<int, array<string, mixed>>  $exceptions
     * @return array<string, mixed>
     */
    private function definition(array $criteria, array $exceptions = []): array
    {
        return [
            'schema_version' => 1,
            'criteria' => [
                'group' => 'all',
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

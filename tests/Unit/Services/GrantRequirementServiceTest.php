<?php

namespace Tests\Unit\Services;

use App\Models\Nation;
use App\Services\GrantRequirementService;
use App\Services\PWHelperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\FeatureTestCase;

class GrantRequirementServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_normalize_coerces_legacy_requirement_definitions(): void
    {
        $service = app(GrantRequirementService::class);

        $normalized = $service->normalize([
            'min_cities' => 5,
            'allowed_colors' => ['blue'],
            'required_projects' => ['Urban Planning'],
        ]);

        $fields = collect($normalized['rules'])->pluck('field')->all();

        $this->assertSame('all', $normalized['group']);
        $this->assertCount(3, $normalized['rules']);
        $this->assertContains('num_cities', $fields);
        $this->assertContains('color', $fields);
        $this->assertContains('projects', $fields);
    }

    public function test_inspect_reports_invalid_fields_and_operators(): void
    {
        $service = app(GrantRequirementService::class);

        $inspection = $service->inspect([
            'group' => 'all',
            'rules' => [
                ['field' => 'unknown_field', 'operator' => 'eq', 'value' => 1],
                ['field' => 'num_cities', 'operator' => 'contains_any', 'value' => 1],
            ],
        ]);

        $this->assertContains('Grant conditions must use a supported field.', $inspection['errors']);
        $this->assertContains('The City count field does not support that operator.', $inspection['errors']);
    }

    public function test_malformed_non_empty_requirement_definitions_are_rejected(): void
    {
        $service = app(GrantRequirementService::class);

        $inspection = $service->inspect('__invalid_json__');
        $evaluation = $service->evaluate('__invalid_json__', $this->makeNation());

        $this->assertSame(
            ['Grant requirements must be an array of groups and conditions.'],
            $inspection['errors'],
        );
        $this->assertFalse($evaluation['passes']);
        $this->assertSame(
            ['This grant has invalid eligibility requirements. Contact an administrator.'],
            $evaluation['failures'],
        );
    }

    public function test_absent_requirement_definitions_remain_unrestricted(): void
    {
        $service = app(GrantRequirementService::class);
        $nation = $this->makeNation();

        $this->assertTrue($service->evaluate(null, $nation)['passes']);
        $this->assertTrue($service->evaluate('', $nation)['passes']);
        $this->assertTrue($service->evaluate([], $nation)['passes']);
    }

    public function test_empty_nested_groups_are_rejected_for_every_group_operator(): void
    {
        $service = app(GrantRequirementService::class);

        foreach (['all', 'any', 'not'] as $group) {
            $inspection = $service->inspect([
                'group' => 'all',
                'rules' => [
                    ['group' => $group, 'rules' => []],
                ],
            ]);

            $this->assertContains(
                'Nested grant requirement groups must contain at least one rule.',
                $inspection['errors'],
                "The {$group} group should not be empty.",
            );
        }
    }

    public function test_empty_top_level_group_still_means_no_requirements(): void
    {
        $service = app(GrantRequirementService::class);

        $inspection = $service->inspect(['group' => 'all', 'rules' => []]);

        $this->assertSame([], $inspection['errors']);
        $this->assertNull($inspection['normalized']);
    }

    public function test_evaluate_supports_nested_any_and_not_groups(): void
    {
        $service = app(GrantRequirementService::class);
        $nation = $this->makeNation();

        $evaluation = $service->evaluate([
            'group' => 'all',
            'rules' => [
                [
                    'group' => 'any',
                    'rules' => [
                        ['field' => 'color', 'operator' => 'eq', 'value' => 'RED', 'message' => ''],
                        ['field' => 'color', 'operator' => 'eq', 'value' => 'BLUE', 'message' => ''],
                    ],
                ],
                [
                    'group' => 'not',
                    'rules' => [
                        ['field' => 'alliance_position', 'operator' => 'eq', 'value' => 'APPLICANT', 'message' => ''],
                    ],
                ],
            ],
        ], $nation);

        $this->assertTrue($evaluation['passes']);
        $this->assertSame([], $evaluation['failures']);
    }

    public function test_assert_eligible_uses_custom_failure_messages(): void
    {
        $service = app(GrantRequirementService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Custom failure message');

        $service->assertEligible([
            'field' => 'num_cities',
            'operator' => 'gte',
            'value' => 10,
            'message' => 'Custom failure message',
        ], $this->makeNation());
    }

    private function makeNation(): Nation
    {
        return Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'num_cities' => 5,
            'color' => 'BLUE',
            'project_bits' => (string) PWHelperService::PROJECTS['Urban Planning'],
        ]);
    }
}

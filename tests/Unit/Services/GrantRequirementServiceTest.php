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

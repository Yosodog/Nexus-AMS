<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\GrowthCircleEnrollment;
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

    public function test_builder_config_includes_growth_circle_enrollment(): void
    {
        $fields = collect(app(GrantRequirementService::class)->getBuilderConfig()['fields'])
            ->keyBy('key');

        $this->assertSame([
            'key' => 'growth_circle_enrollment',
            'label' => 'Growth Circles enrollment',
            'category' => 'Programs',
            'type' => 'enum',
            'operators' => ['eq', 'neq'],
            'options' => [
                ['value' => 'ENROLLED', 'label' => 'Enrolled'],
                ['value' => 'NOT_ENROLLED', 'label' => 'Not Enrolled'],
            ],
        ], $fields->get('growth_circle_enrollment'));
    }

    public function test_growth_circle_enrollment_requirement_rejects_an_unenrolled_nation(): void
    {
        $evaluation = app(GrantRequirementService::class)->evaluate(
            $this->growthCircleEnrollmentRequirement(),
            $this->makeNation(),
        );

        $this->assertFalse($evaluation['passes']);
        $this->assertSame(
            ['Growth Circles enrollment must be Enrolled.'],
            $evaluation['failures'],
        );
        $this->assertSame(
            ['Growth Circles enrollment is Enrolled'],
            $evaluation['summary'],
        );
    }

    public function test_growth_circle_enrollment_requirement_accepts_an_enrolled_nation(): void
    {
        $nation = $this->makeNation();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Growth Circles';
        $account->save();

        GrowthCircleEnrollment::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'previous_tax_id' => null,
            'enrolled_at' => now(),
        ]);

        $evaluation = app(GrantRequirementService::class)->evaluate(
            $this->growthCircleEnrollmentRequirement(),
            $nation,
        );

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

    public function test_normalize_coerces_city_infrastructure_requirements(): void
    {
        $normalized = app(GrantRequirementService::class)->normalize([
            'minimum_infra_per_city' => 1800,
        ]);

        $this->assertSame([
            'group' => 'all',
            'rules' => [[
                'field' => 'avg_infrastructure_per_city',
                'operator' => 'gte',
                'value' => 1800.0,
                'message' => '',
            ]],
        ], $normalized);
    }

    public function test_guaranteed_projects_supports_nested_group_semantics(): void
    {
        $service = app(GrantRequirementService::class);

        $requirements = [
            'group' => 'all',
            'rules' => [
                [
                    'field' => 'projects',
                    'operator' => 'contains_all',
                    'value' => ['Bureau of Domestic Affairs'],
                    'message' => '',
                ],
                [
                    'group' => 'any',
                    'rules' => [
                        [
                            'field' => 'projects',
                            'operator' => 'contains_all',
                            'value' => ['Government Support Agency', 'Urban Planning'],
                            'message' => '',
                        ],
                        [
                            'field' => 'projects',
                            'operator' => 'contains_any',
                            'value' => ['Government Support Agency'],
                            'message' => '',
                        ],
                    ],
                ],
                [
                    'group' => 'not',
                    'rules' => [[
                        'field' => 'projects',
                        'operator' => 'contains_all',
                        'value' => ['Advanced Urban Planning'],
                        'message' => '',
                    ]],
                ],
            ],
        ];

        $this->assertEqualsCanonicalizing([
            'Bureau of Domestic Affairs',
            'Government Support Agency',
        ], $service->guaranteedProjects($requirements));
    }

    public function test_guaranteed_projects_does_not_infer_from_optional_project_paths(): void
    {
        $requirements = [
            'group' => 'any',
            'rules' => [
                [
                    'field' => 'projects',
                    'operator' => 'contains_all',
                    'value' => ['Bureau of Domestic Affairs'],
                    'message' => '',
                ],
                [
                    'field' => 'num_cities',
                    'operator' => 'gte',
                    'value' => 10,
                    'message' => '',
                ],
            ],
        ];

        $this->assertSame([], app(GrantRequirementService::class)->guaranteedProjects($requirements));
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

    /**
     * @return array<string, mixed>
     */
    private function growthCircleEnrollmentRequirement(): array
    {
        return [
            'group' => 'all',
            'rules' => [[
                'field' => 'growth_circle_enrollment',
                'operator' => 'eq',
                'value' => 'ENROLLED',
                'message' => '',
            ]],
        ];
    }
}

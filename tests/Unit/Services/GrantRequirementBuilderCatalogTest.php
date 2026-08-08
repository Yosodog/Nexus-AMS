<?php

namespace Tests\Unit\Services;

use App\Services\GrantRequirements\GrantRequirementBuilderCatalog;
use App\Services\GrantRequirementService;
use App\Services\PWHelperService;
use App\Services\Rules\RuleTreeKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GrantRequirementBuilderCatalogTest extends TestCase
{
    /**
     * Protects the complete pre-extraction builder contract without duplicating its full metadata fixture.
     */
    private const PRE_EXTRACTION_BUILDER_CONTRACT_SHA256 = 'f2619090a00db59025f262b6959bef339125b6aa1da53ee3d117ad854491e439';

    private GrantRequirementBuilderCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = new GrantRequirementBuilderCatalog;
    }

    public function test_service_builder_config_matches_the_extracted_catalog(): void
    {
        $service = new GrantRequirementService(new RuleTreeKernel, $this->catalog);

        $this->assertSame(
            $this->catalog->getBuilderConfig($service->emptyTree()),
            $service->getBuilderConfig(),
        );
    }

    public function test_service_preserves_its_existing_single_dependency_constructor(): void
    {
        $service = new GrantRequirementService(new RuleTreeKernel);

        $this->assertSame(
            $this->catalog->getBuilderConfig($service->emptyTree()),
            $service->getBuilderConfig(),
        );
    }

    public function test_builder_groups_and_operators_preserve_the_existing_contract(): void
    {
        $config = $this->catalog->getBuilderConfig([
            'group' => 'all',
            'rules' => [],
        ]);

        $this->assertSame([
            ['value' => 'all', 'label' => 'All conditions must match'],
            ['value' => 'any', 'label' => 'Any condition may match'],
            ['value' => 'not', 'label' => 'None of these may match'],
        ], $config['groups']);
        $this->assertSame([
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
        ], $config['operators']);
        $this->assertSame([
            'group' => 'all',
            'rules' => [],
        ], $config['default_tree']);
    }

    public function test_field_metadata_preserves_numeric_enum_collection_and_resource_shapes(): void
    {
        $fields = $this->catalog->fields();

        $this->assertSame([
            'key' => 'num_cities',
            'label' => 'City count',
            'category' => 'Nation',
            'type' => 'number',
            'operators' => ['gt', 'gte', 'lt', 'lte', 'eq', 'neq', 'between', 'not_between'],
        ], $fields['num_cities']);
        $this->assertSame([
            ['value' => 'ENROLLED', 'label' => 'Enrolled'],
            ['value' => 'NOT_ENROLLED', 'label' => 'Not Enrolled'],
        ], $fields['growth_circle_enrollment']['options']);
        $this->assertSame('North America', collect($fields['continent']['options'])
            ->firstWhere('value', 'NORTH_AMERICA')['label']);
        $this->assertSame(
            PWHelperService::projects(),
            collect($fields['projects']['options'])->pluck('value')->all(),
        );

        foreach (PWHelperService::resources(true, true) as $resource) {
            $this->assertSame('number', $fields[$resource]['type']);
            $this->assertSame('Resources', $fields[$resource]['category']);
        }
    }

    public function test_complete_ordered_builder_contract_matches_pre_extraction_golden_fingerprint(): void
    {
        $contract = $this->catalog->getBuilderConfig([
            'group' => 'all',
            'rules' => [],
        ]);
        $canonicalJson = $this->canonicalJson($contract);

        $this->assertSame(['groups', 'operators', 'fields', 'default_tree'], array_keys($contract));
        $this->assertCount(3, $contract['groups']);
        $this->assertCount(13, $contract['operators']);
        $this->assertCount(50, $contract['fields']);
        $this->assertSame(self::PRE_EXTRACTION_BUILDER_CONTRACT_SHA256, hash('sha256', $canonicalJson));

        $reorderedContract = $contract;
        [$reorderedContract['fields'][0], $reorderedContract['fields'][1]] = [
            $reorderedContract['fields'][1],
            $reorderedContract['fields'][0],
        ];

        $this->assertNotSame(
            self::PRE_EXTRACTION_BUILDER_CONTRACT_SHA256,
            hash('sha256', $this->canonicalJson($reorderedContract)),
        );
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function displayValueCases(): array
    {
        return [
            'whole number' => [12.00, '12'],
            'decimal number' => [12.50, '12.5'],
            'machine enum' => ['NORTH_AMERICA', 'North America'],
            'mixed-case project' => ['Urban Planning', 'Urban Planning'],
            'empty value' => ['', ''],
        ];
    }

    #[DataProvider('displayValueCases')]
    public function test_display_values_preserve_numeric_and_humanization_edge_behavior(
        mixed $value,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->catalog->displayValue($value));
    }

    public function test_display_list_preserves_canonical_order_and_handles_empty_values(): void
    {
        $this->assertSame(
            'North America, 12.5, Urban Planning',
            $this->catalog->displayList(['NORTH_AMERICA', 12.50, 'Urban Planning']),
        );
        $this->assertSame('', $this->catalog->displayList([]));
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function canonicalJson(array $contract): string
    {
        $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (array_is_list($value)) {
                return array_map($canonicalize, $value);
            }

            ksort($value, SORT_STRING);

            foreach ($value as $key => $item) {
                $value[$key] = $canonicalize($item);
            }

            return $value;
        };

        return json_encode(
            $canonicalize($contract),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}

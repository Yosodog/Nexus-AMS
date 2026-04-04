<?php

namespace Tests\Unit\Services;

use App\Services\GraphQLQueryBuilder;
use Tests\UnitTestCase;

class GraphQLQueryBuilderTest extends UnitTestCase
{
    public function test_it_builds_nested_queries_with_arguments(): void
    {
        $query = (new GraphQLQueryBuilder)
            ->setRootField('nations')
            ->addArgument([
                'id' => 123,
                'active' => true,
                'filters' => ['score_min' => 1000],
            ])
            ->addNestedField('data', function (GraphQLQueryBuilder $builder): void {
                $builder->addFields(['id', 'nation_name']);
            })
            ->build();

        $this->assertSame(
            'query { nations(id: 123, active: true, filters: { score_min: 1000 }) { data { id nation_name } } }',
            $query
        );
    }
}

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

    public function test_it_does_not_escape_apostrophes_in_string_arguments(): void
    {
        $query = (new GraphQLQueryBuilder)
            ->setRootField('bankWithdraw')
            ->addArgument('note', "Withdraw from reggie's pockets")
            ->addFields('id')
            ->build();

        $this->assertSame(
            'query { bankWithdraw(note: "Withdraw from reggie\'s pockets") { id } }',
            $query
        );
    }

    public function test_it_uses_graphql_compatible_escapes_for_string_arguments(): void
    {
        $query = (new GraphQLQueryBuilder)
            ->setRootField('bankWithdraw')
            ->addArgument('note', "A \"quoted\" path \\ with\na new line\tand a tab")
            ->addFields('id')
            ->build();

        $this->assertSame(
            'query { bankWithdraw(note: "A \\"quoted\\" path \\\\ with\\na new line\\tand a tab") { id } }',
            $query
        );
    }
}

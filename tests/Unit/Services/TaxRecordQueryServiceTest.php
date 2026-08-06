<?php

namespace Tests\Unit\Services;

use App\Exceptions\PWQueryFailedException;
use App\Services\GraphQLQueryBuilder;
use App\Services\PWHelperService;
use App\Services\QueryService;
use App\Services\TaxRecordQueryService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class TaxRecordQueryServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @return array<string, mixed>
     */
    private function bankRecordPayload(int $id): array
    {
        return [
            'id' => $id,
            'date' => '2026-08-05T12:00:00+00:00',
            'sender_id' => 123,
            'sender_type' => 1,
            'receiver_id' => 777,
            'receiver_type' => 2,
            'banker_id' => 1,
            'note' => 'Tax query test',
            'tax_id' => 1,
            ...collect(PWHelperService::resources())
                ->mapWithKeys(fn (string $resource): array => [$resource => 0])
                ->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function allianceTaxResponse(array $records, int $allianceId = 777): object
    {
        return (object) [[
            'id' => (string) $allianceId,
            'taxrecs' => $records,
        ]];
    }

    public function test_it_queries_the_nested_alliance_tax_feed_in_ordered_chunks(): void
    {
        $client = Mockery::mock(QueryService::class);
        $client->shouldReceive('sendQuery')
            ->once()
            ->ordered()
            ->withArgs(function (GraphQLQueryBuilder $builder): bool {
                $query = $builder->build();

                return str_contains($query, 'alliances(id: 777)')
                    && str_contains($query, 'taxrecs(min_id: 10, limit: 2')
                    && str_contains($query, 'column: ID')
                    && str_contains($query, 'order: ASC')
                    && ! str_contains($query, 'bankrecs')
                    && ! str_contains($query, 'paginatorInfo');
            })
            ->andReturn($this->allianceTaxResponse([
                $this->bankRecordPayload(10),
                $this->bankRecordPayload(11),
            ]));
        $client->shouldReceive('sendQuery')
            ->once()
            ->ordered()
            ->withArgs(function (GraphQLQueryBuilder $builder): bool {
                return str_contains($builder->build(), 'taxrecs(min_id: 12, limit: 2');
            })
            ->andReturn($this->allianceTaxResponse([
                $this->bankRecordPayload(12),
            ]));

        $records = (new TaxRecordQueryService)->getAllianceTaxes(
            allianceId: 777,
            minimumId: 10,
            limit: 2,
            client: $client,
        );
        $ids = [];

        foreach ($records as $record) {
            $ids[] = $record->id;
        }

        $this->assertSame([10, 11, 12], $ids);
        $this->assertSame(3, $records->count());
    }

    public function test_it_rejects_a_response_without_the_requested_alliance(): void
    {
        $client = Mockery::mock(QueryService::class);
        $client->shouldReceive('sendQuery')
            ->once()
            ->andReturn($this->allianceTaxResponse([], allianceId: 888));

        $this->expectException(PWQueryFailedException::class);

        (new TaxRecordQueryService)->getAllianceTaxes(777, client: $client);
    }

    public function test_it_rejects_a_response_that_omits_the_tax_feed(): void
    {
        $client = Mockery::mock(QueryService::class);
        $client->shouldReceive('sendQuery')
            ->once()
            ->andReturn((object) [[
                'id' => '777',
            ]]);

        $this->expectException(PWQueryFailedException::class);

        (new TaxRecordQueryService)->getAllianceTaxes(777, client: $client);
    }
}

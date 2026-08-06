<?php

namespace Tests\Unit\Services;

use App\Models\Taxes;
use App\Models\TaxImportCheckpoint;
use App\Services\GraphQLQueryBuilder;
use App\Services\PWHelperService;
use App\Services\QueryService;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class TaxImportCheckpointTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_tax_import_stops_at_first_failed_record_without_advancing_past_gap(): void
    {
        Taxes::query()->create($this->taxRow([
            'id' => 101,
            'receiver_id' => 888,
        ]));

        $client = Mockery::mock(QueryService::class);
        $client->shouldReceive('sendQuery')
            ->once()
            ->andReturn($this->allianceTaxResponse([
                $this->bankRecordPayload(101, receiverId: 777),
                $this->bankRecordPayload(102, receiverId: 777),
            ]));

        $lastScanned = TaxService::updateAllianceTaxes(777, $client);

        $this->assertSame(0, $lastScanned);
        $this->assertDatabaseMissing('taxes', [
            'id' => 102,
            'receiver_id' => 777,
        ]);
    }

    public function test_tax_import_quarantines_invalid_timestamps_and_continues(): void
    {
        $client = Mockery::mock(QueryService::class);
        $client->shouldReceive('sendQuery')
            ->once()
            ->andReturn($this->allianceTaxResponse([
                $this->bankRecordPayload(101, receiverId: 777, date: 'not-a-timestamp'),
                $this->bankRecordPayload(102, receiverId: 777),
            ]));

        $lastScanned = TaxService::updateAllianceTaxes(777, $client);

        $this->assertSame(102, $lastScanned);
        $this->assertDatabaseHas('tax_import_rejections', [
            'alliance_id' => 777,
            'tax_record_id' => 101,
            'reason' => 'invalid_timestamp',
            'raw_timestamp' => 'not-a-timestamp',
        ]);
        $this->assertDatabaseMissing('taxes', ['id' => 101]);
        $this->assertDatabaseHas('taxes', [
            'id' => 102,
            'receiver_id' => 777,
        ]);
        $this->assertDatabaseHas('tax_import_checkpoints', [
            'alliance_id' => 777,
            'last_scanned_id' => 102,
        ]);
    }

    public function test_tax_import_orders_records_before_advancing_its_checkpoint(): void
    {
        Taxes::query()->create($this->taxRow([
            'id' => 102,
            'receiver_id' => 888,
        ]));

        $client = Mockery::mock(QueryService::class);
        $client->shouldReceive('sendQuery')
            ->once()
            ->andReturn($this->allianceTaxResponse([
                $this->bankRecordPayload(103, receiverId: 777),
                $this->bankRecordPayload(102, receiverId: 777),
                $this->bankRecordPayload(101, receiverId: 777),
            ]));

        $lastScanned = TaxService::updateAllianceTaxes(777, $client);

        $this->assertSame(101, $lastScanned);
        $this->assertDatabaseHas('taxes', [
            'id' => 101,
            'receiver_id' => 777,
        ]);
        $this->assertDatabaseMissing('taxes', [
            'id' => 103,
            'receiver_id' => 777,
        ]);
        $this->assertDatabaseHas('tax_import_checkpoints', [
            'alliance_id' => 777,
            'last_scanned_id' => 101,
        ]);
    }

    public function test_tax_import_does_not_advance_past_a_non_tax_record(): void
    {
        $client = Mockery::mock(QueryService::class);
        $client->shouldReceive('sendQuery')
            ->once()
            ->andReturn($this->allianceTaxResponse([
                $this->bankRecordPayload(101, receiverId: 777, taxId: 0),
                $this->bankRecordPayload(102, receiverId: 777),
            ]));

        $this->assertSame(0, TaxService::updateAllianceTaxes(777, $client));
        $this->assertDatabaseMissing('taxes', ['id' => 101]);
        $this->assertDatabaseMissing('taxes', ['id' => 102]);
        $this->assertDatabaseHas('tax_import_checkpoints', [
            'alliance_id' => 777,
            'last_scanned_id' => 0,
        ]);
    }

    public function test_tax_import_rewinds_an_invalid_checkpoint_to_the_last_durable_tax_id(): void
    {
        Taxes::query()->create($this->taxRow([
            'id' => 100,
            'receiver_id' => 777,
        ]));
        TaxImportCheckpoint::query()->create([
            'alliance_id' => 777,
            'last_scanned_id' => 999,
        ]);

        $client = Mockery::mock(QueryService::class);
        $client->shouldReceive('sendQuery')
            ->once()
            ->withArgs(function (GraphQLQueryBuilder $builder): bool {
                return str_contains($builder->build(), 'min_id: 101');
            })
            ->andReturn($this->allianceTaxResponse([
                $this->bankRecordPayload(101, receiverId: 777),
            ]));

        $this->assertSame(101, TaxService::updateAllianceTaxes(777, $client));
        $this->assertDatabaseHas('taxes', [
            'id' => 101,
            'receiver_id' => 777,
        ]);
        $this->assertDatabaseHas('tax_import_checkpoints', [
            'alliance_id' => 777,
            'last_scanned_id' => 101,
        ]);
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function taxRow(array $overrides = []): array
    {
        return [
            'id' => 100,
            'date' => now(),
            'sender_id' => 123,
            'receiver_id' => 777,
            'receiver_type' => 2,
            'tax_id' => 1,
            ...$this->resourcePayload(['money' => 10]),
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bankRecordPayload(
        int $id,
        int $receiverId,
        ?string $date = null,
        int $taxId = 1
    ): array {
        return [
            'id' => $id,
            'date' => $date ?? now()->toISOString(),
            'sender_id' => 123,
            'sender_type' => 1,
            'receiver_id' => $receiverId,
            'receiver_type' => 2,
            'banker_id' => 1,
            'note' => 'Tax import test',
            'tax_id' => $taxId,
            ...$this->resourcePayload(['money' => 10]),
        ];
    }

    /**
     * @param  array<string, float|int>  $overrides
     * @return array<string, float|int>
     */
    private function resourcePayload(array $overrides = []): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => $overrides[$resource] ?? 0])
            ->all();
    }
}

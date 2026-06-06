<?php

namespace Tests\Unit\Services;

use App\Models\Taxes;
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
            ->andReturn((object) [
                (object) $this->alliancePayload([
                    $this->bankRecordPayload(101, receiverId: 777),
                    $this->bankRecordPayload(102, receiverId: 777),
                ]),
            ]);

        $lastScanned = TaxService::updateAllianceTaxes(777, $client);

        $this->assertSame(0, $lastScanned);
        $this->assertDatabaseMissing('taxes', [
            'id' => 102,
            'receiver_id' => 777,
        ]);
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
     * @param  array<int, array<string, mixed>>  $taxRecords
     * @return array<string, mixed>
     */
    private function alliancePayload(array $taxRecords): array
    {
        return [
            'id' => '777',
            'name' => 'Test Alliance',
            'acronym' => 'TA',
            'score' => 1000.0,
            'color' => 'blue',
            'average_score' => 500.0,
            'accept_members' => true,
            'flag' => 'https://example.test/flag.png',
            'forum_link' => 'https://example.test/forum',
            'discord_link' => 'https://example.test/discord',
            'wiki_link' => null,
            'rank' => 1,
            'taxrecs' => $taxRecords,
            ...$this->resourcePayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bankRecordPayload(int $id, int $receiverId): array
    {
        return [
            'id' => $id,
            'date' => now()->toISOString(),
            'sender_id' => 123,
            'sender_type' => 1,
            'receiver_id' => $receiverId,
            'receiver_type' => 2,
            'banker_id' => 1,
            'note' => 'Tax import test',
            'tax_id' => 1,
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

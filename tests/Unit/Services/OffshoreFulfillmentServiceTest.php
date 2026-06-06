<?php

namespace Tests\Unit\Services;

use App\Exceptions\PWQueryFailedException;
use App\Models\Offshore;
use App\Models\Transaction;
use App\Services\AllianceMembershipService;
use App\Services\OffshoreFulfillmentResult;
use App\Services\OffshoreFulfillmentService;
use App\Services\OffshoreService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class OffshoreFulfillmentServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_incomplete_plan_does_not_send_any_offshore_withdrawals(): void
    {
        $firstOffshore = $this->offshore(1, 'First Offshore');
        $secondOffshore = $this->offshore(2, 'Second Offshore');
        $offshoreService = Mockery::mock(OffshoreService::class);
        $offshoreService->shouldReceive('all')
            ->once()
            ->andReturn(collect([$firstOffshore, $secondOffshore]));
        $offshoreService->shouldReceive('refreshBalances')
            ->once()
            ->with($firstOffshore)
            ->andReturn(['money' => 400]);
        $offshoreService->shouldReceive('refreshBalances')
            ->once()
            ->with($secondOffshore)
            ->andReturn(['money' => 100]);
        $offshoreService->shouldReceive('guardrailFor')
            ->twice()
            ->andReturn(null);

        $service = $this->service($offshoreService);
        $service->mainBalances = ['money' => 0];

        $result = $service->coverShortfall($this->transaction(['money' => 1000]));

        $this->assertSame(OffshoreFulfillmentResult::STATUS_FAILED, $result->status);
        $this->assertSame([], $service->sentPayloads);
        $this->assertEquals(['money' => 500.0], $result->remainingDeficits);
        $this->assertSame(['not_sent', 'not_sent'], array_column($result->plannedTransfers, 'status'));
    }

    public function test_execution_failure_stops_and_records_reconciliation_state(): void
    {
        $firstOffshore = $this->offshore(1, 'First Offshore');
        $secondOffshore = $this->offshore(2, 'Second Offshore');
        $offshoreService = Mockery::mock(OffshoreService::class);
        $offshoreService->shouldReceive('all')
            ->once()
            ->andReturn(collect([$firstOffshore, $secondOffshore]));
        $offshoreService->shouldReceive('refreshBalances')
            ->twice()
            ->with($firstOffshore)
            ->andReturn(['money' => 400]);
        $offshoreService->shouldReceive('refreshBalances')
            ->once()
            ->with($secondOffshore)
            ->andReturn(['money' => 600]);
        $offshoreService->shouldReceive('guardrailFor')
            ->twice()
            ->andReturn(null);

        $service = $this->service($offshoreService);
        $service->mainBalances = ['money' => 0];
        $service->failOnOffshoreId = 2;

        $result = $service->coverShortfall($this->transaction(['money' => 1000]));

        $this->assertSame(OffshoreFulfillmentResult::STATUS_FAILED, $result->status);
        $this->assertCount(2, $service->sentPayloads);
        $this->assertSame(1, $result->transfers[0]['offshore_id']);
        $this->assertEquals(['money' => 600.0], $result->remainingDeficits);
        $this->assertSame(['sent', 'review_required'], array_column($result->plannedTransfers, 'status'));
        $this->assertSame('mutation failed', $result->plannedTransfers[1]['error']);
    }

    private function service(OffshoreService $offshoreService): TestableOffshoreFulfillmentService
    {
        $membershipService = Mockery::mock(AllianceMembershipService::class);
        $membershipService->shouldReceive('getPrimaryAllianceId')
            ->andReturn(999);

        return new TestableOffshoreFulfillmentService($offshoreService, $membershipService);
    }

    private function offshore(int $id, string $name): Offshore
    {
        $offshore = new Offshore(['name' => $name]);
        $offshore->id = $id;
        $offshore->exists = true;

        return $offshore;
    }

    /**
     * @param  array<string, int|float>  $resources
     */
    private function transaction(array $resources): Transaction
    {
        $transaction = new Transaction;
        $transaction->id = 123;

        foreach ($resources as $resource => $amount) {
            $transaction->$resource = $amount;
        }

        return $transaction;
    }
}

class TestableOffshoreFulfillmentService extends OffshoreFulfillmentService
{
    /**
     * @var array<string, float>
     */
    public array $mainBalances = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $sentPayloads = [];

    public ?int $failOnOffshoreId = null;

    protected function getMainAllianceBalances(): ?array
    {
        return $this->mainBalances;
    }

    protected function credentialErrorFor(Offshore $offshore): ?array
    {
        return null;
    }

    protected function sendOffshoreWithdrawal(Offshore $offshore, Transaction $transaction, array $payload): void
    {
        $this->sentPayloads[] = [
            'offshore_id' => $offshore->id,
            'resources' => $payload,
        ];

        if ($this->failOnOffshoreId === $offshore->id) {
            throw new PWQueryFailedException('mutation failed');
        }
    }
}

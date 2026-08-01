<?php

namespace Tests\Feature\Security;

use App\Exceptions\OffshoreTransferException;
use App\Models\Alliance;
use App\Models\Offshore;
use App\Models\OffshoreTransfer;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\OffshoreService;
use App\Services\OffshoreTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class OffshoreToOffshoreTransferSecurityTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_manual_request_rejects_offshore_to_offshore_transfers_before_dispatch(): void
    {
        [$source, $destination] = $this->createOffshores();
        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(),
            ['manage-offshores']
        );

        $this->mock(OffshoreTransferService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('transfer');
        });

        $response = $this->actingAs($admin)
            ->from(route('admin.offshores.index'))
            ->post(route('admin.offshores.transfer'), [
                'source_type' => OffshoreTransfer::TYPE_OFFSHORE,
                'source_offshore_id' => $source->id,
                'destination_type' => OffshoreTransfer::TYPE_OFFSHORE,
                'destination_offshore_id' => $destination->id,
                'resources' => ['money' => 100],
            ]);

        $response
            ->assertRedirect(route('admin.offshores.index'))
            ->assertSessionHasErrors('destination_type');
        $this->assertStringContainsString(
            'cannot be completed atomically',
            session('errors')->first('destination_type')
        );
        $this->assertDatabaseCount('offshore_transfers', 0);
    }

    public function test_service_rejects_offshore_to_offshore_transfers_without_persisting_or_dispatching(): void
    {
        [$source, $destination] = $this->createOffshores();
        $offshoreService = $this->createMock(OffshoreService::class);
        $offshoreService->expects($this->never())->method('refreshBalances');
        $membershipService = $this->createMock(AllianceMembershipService::class);
        $membershipService->expects($this->never())->method('getPrimaryAllianceId');

        $service = new class($offshoreService, $membershipService, 777) extends OffshoreTransferService
        {
            public int $dispatchCount = 0;

            protected function sendFromMainToOffshore(Offshore $offshore, array $payload, string $note): void
            {
                $this->dispatchCount++;
            }

            protected function sendFromOffshoreToMain(Offshore $offshore, array $payload, string $note): void
            {
                $this->dispatchCount++;
            }
        };

        try {
            $service->transfer(
                OffshoreTransfer::TYPE_OFFSHORE,
                $source,
                OffshoreTransfer::TYPE_OFFSHORE,
                $destination,
                ['money' => 100.0],
                User::factory()->create(),
            );
            $this->fail('Offshore-to-offshore transfers must be rejected.');
        } catch (OffshoreTransferException $exception) {
            $this->assertStringContainsString('cannot be completed atomically', $exception->getMessage());
        }

        $this->assertSame(0, $service->dispatchCount);
        $this->assertDatabaseCount('offshore_transfers', 0);
    }

    /**
     * @return array{Offshore, Offshore}
     */
    private function createOffshores(): array
    {
        $source = Offshore::query()->create([
            'name' => 'Source Offshore',
            'alliance_id' => Alliance::factory()->create()->id,
            'enabled' => true,
            'priority' => 1,
        ]);
        $destination = Offshore::query()->create([
            'name' => 'Destination Offshore',
            'alliance_id' => Alliance::factory()->create()->id,
            'enabled' => true,
            'priority' => 2,
        ]);

        return [$source, $destination];
    }
}

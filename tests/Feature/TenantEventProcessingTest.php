<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataTransferObjects\TenantEvents\TenantEvent;
use App\Enums\NexusRuntime;
use App\Enums\TenantEventProcessingResult;
use App\Enums\TenantEventRejectionReason;
use App\Enums\TenantEventType;
use App\Events\WarDeclared;
use App\Exceptions\TenantEventConflictException;
use App\Exceptions\TenantEventRejectedException;
use App\Exceptions\TenantEventRetryableException;
use App\Models\TenantEventReceipt;
use App\Models\War;
use App\Services\RuntimeCapabilities;
use App\Services\TenantEvents\TenantEventProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class TenantEventProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-08 22:00:05 UTC');
    }

    public function test_war_event_receipt_and_domain_effect_commit_once(): void
    {
        Event::fake([WarDeclared::class]);
        $this->createWar();
        $event = $this->tenantEvent();

        $this->assertSame(TenantEventProcessingResult::Processed, $this->processor()->process($event));
        $this->assertSame(TenantEventProcessingResult::Duplicate, $this->processor()->process($event));
        $this->assertSame(
            TenantEventProcessingResult::Duplicate,
            $this->processor()->process($this->tenantEvent([
                'transportNonce' => str_repeat('b', 32),
                'publishedAt' => CarbonImmutable::parse('2026-08-08T22:00:06Z'),
            ])),
        );

        $this->assertDatabaseHas('tenant_event_receipts', [
            'delivery_id' => $event->deliveryId,
            'event_id' => $event->eventId,
            'contract_version' => 1,
            'event_type' => TenantEventType::WarDeclared->value,
            'subject_key' => 'war:123456',
            'event_digest' => $event->bodyDigest,
            'transport_nonce' => $event->transportNonce,
            'trace_id' => $event->traceId,
        ]);
        $this->assertDatabaseHas('war_declaration_receipts', ['war_id' => 123456]);
        $this->assertSame(1, TenantEventReceipt::query()->count());
        $this->assertFalse(Schema::hasColumn('tenant_event_receipts', 'tenant_id'));
        $this->assertFalse(Schema::hasColumn('tenant_event_receipts', 'payload'));
        Event::assertDispatchedTimes(WarDeclared::class, 1);
    }

    public function test_conflicting_event_and_delivery_identity_are_rejected(): void
    {
        Event::fake([WarDeclared::class]);
        $this->createWar();
        $event = $this->tenantEvent();
        $this->processor()->process($event);

        foreach ([
            $this->tenantEvent([
                'deliveryId' => $event->deliveryId,
                'eventId' => 'world:war:123456:create:conflict',
                'transportNonce' => str_repeat('b', 32),
                'bodyDigest' => hash('sha256', 'different-event'),
            ]),
            $this->tenantEvent([
                'deliveryId' => (string) Str::ulid(),
                'eventId' => $event->eventId,
                'transportNonce' => str_repeat('c', 32),
                'bodyDigest' => hash('sha256', 'altered-body'),
            ]),
        ] as $conflict) {
            try {
                $this->processor()->process($conflict);
                $this->fail('A conflicting tenant event was accepted.');
            } catch (TenantEventConflictException $exception) {
                $this->assertSame(
                    'Tenant event identity conflicts with an existing receipt.',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertSame(1, TenantEventReceipt::query()->count());
        Event::assertDispatchedTimes(WarDeclared::class, 1);
    }

    public function test_missing_world_row_and_routing_mismatch_leave_no_receipt_or_effect(): void
    {
        Event::fake([WarDeclared::class]);

        try {
            $this->processor()->process($this->tenantEvent());
            $this->fail('A tenant event with a missing world row was accepted.');
        } catch (TenantEventRetryableException $exception) {
            $this->assertSame('Tenant event dependency is not ready.', $exception->getMessage());
        }

        $this->assertDatabaseCount('tenant_event_receipts', 0);
        $this->createWar();

        try {
            $this->processor()->process($this->tenantEvent([
                'matchedAllianceIds' => [99999],
            ]));
            $this->fail('A tenant event with inconsistent routing evidence was accepted.');
        } catch (TenantEventRejectedException $exception) {
            $this->assertSame(TenantEventRejectionReason::RoutingMismatch, $exception->reason);
        }

        $this->assertDatabaseCount('tenant_event_receipts', 0);
        $this->assertDatabaseCount('war_declaration_receipts', 0);
        Event::assertNotDispatched(WarDeclared::class);
    }

    public function test_listener_failure_rolls_back_both_receipt_layers_for_retry(): void
    {
        $this->createWar();
        Event::forget(WarDeclared::class);
        Event::listen(WarDeclared::class, static function (): never {
            throw new RuntimeException('sensitive-listener-failure');
        });

        try {
            $this->processor()->process($this->tenantEvent());
            $this->fail('A failed war reaction was committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('sensitive-listener-failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('tenant_event_receipts', 0);
        $this->assertDatabaseCount('war_declaration_receipts', 0);

        Event::forget(WarDeclared::class);
        Event::fake([WarDeclared::class]);
        $this->assertSame(
            TenantEventProcessingResult::Processed,
            $this->processor()->process($this->tenantEvent()),
        );
        Event::assertDispatchedTimes(WarDeclared::class, 1);
    }

    public function test_domain_unique_constraint_failure_is_not_misclassified_as_receipt_conflict(): void
    {
        $this->createWar();
        $event = $this->tenantEvent();
        Event::forget(WarDeclared::class);
        Event::listen(WarDeclared::class, static function () use ($event): void {
            TenantEventReceipt::factory()->create([
                'delivery_id' => $event->deliveryId,
            ]);
        });

        try {
            $this->processor()->process($event);
            $this->fail('A domain unique-constraint failure was swallowed.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('tenant_event_receipts', 0);
            $this->assertDatabaseCount('war_declaration_receipts', 0);
        }
    }

    public function test_receipts_are_immutable_and_migration_repairs_missing_indexes(): void
    {
        $receipt = TenantEventReceipt::factory()->create();
        $receipt->event_id = 'world:war:1:create:v1';

        try {
            $receipt->save();
            $this->fail('An immutable tenant event receipt was updated.');
        } catch (LogicException $exception) {
            $this->assertSame('Tenant event receipts are immutable.', $exception->getMessage());
        }

        Schema::table('tenant_event_receipts', function (Blueprint $table): void {
            $table->dropIndex('tenant_event_receipts_subject_index');
        });
        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex(
            'tenant_event_receipts',
            ['event_type', 'subject_key'],
        ));
        $this->assertDatabaseHas('tenant_event_receipts', ['id' => $receipt->id]);
    }

    public function test_migration_refuses_an_incomplete_preexisting_receipt_table(): void
    {
        Schema::drop('tenant_event_receipts');
        Schema::create('tenant_event_receipts', function (Blueprint $table): void {
            $table->id();
        });

        try {
            $this->migration()->up();
            $this->fail('An incomplete tenant event receipt table was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The tenant_event_receipts table is incomplete; repair it before resuming migration.',
                $exception->getMessage(),
            );
        } finally {
            Schema::dropIfExists('tenant_event_receipts');
            $this->migration()->up();
        }
    }

    public function test_disabled_command_short_circuits_before_database_key_or_redis_access(): void
    {
        config([
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'nexus.managed' => true,
            'nexus.tenant_events.enabled' => false,
            'nexus.tenant_events.key_file' => '/missing/private/tenant-event-key',
            'database.redis.tenant_events.url' => 'redis://invalid.invalid:6379/0',
        ]);
        $this->forgetRuntimeSingletons();
        Schema::drop('tenant_event_receipts');

        try {
            $this->artisan('nexus:consume-tenant-events', ['--once' => true])
                ->expectsOutputToContain('Tenant events are not enabled for this runtime.')
                ->assertSuccessful();
        } finally {
            $this->migration()->up();
        }
    }

    private function createWar(): War
    {
        return War::query()->create([
            'id' => 123456,
            'date' => now(),
            'reason' => 'Tenant event test',
            'war_type' => 'ORDINARY',
            'turns_left' => 12,
            'att_id' => 10,
            'att_alliance_id' => 10014,
            'att_alliance_position' => 'MEMBER',
            'def_id' => 20,
            'def_alliance_id' => 20028,
            'def_alliance_position' => 'OFFICER',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function tenantEvent(array $overrides = []): TenantEvent
    {
        $attributes = array_replace([
            'deliveryId' => '01KZHV17VQ9S6GDGBK0QJ5GF1Y',
            'eventId' => 'world:war:123456:create:v1',
            'contractVersion' => 1,
            'tenantId' => '01KZHV17VQ9S6GDGBK0QJ5GF1Z',
            'type' => TenantEventType::WarDeclared,
            'subjectId' => 123456,
            'matchedAllianceIds' => [10014],
            'occurredAt' => CarbonImmutable::parse('2026-08-08T22:00:00Z'),
            'traceId' => '01KZHV17VQ9S6GDGBK0QJ5GF20',
            'bodyDigest' => hash('sha256', 'tenant-event-body'),
            'transportNonce' => str_repeat('a', 32),
            'publishedAt' => CarbonImmutable::parse('2026-08-08T22:00:05Z'),
        ], $overrides);

        return new TenantEvent(...$attributes);
    }

    private function processor(): TenantEventProcessor
    {
        return app(TenantEventProcessor::class);
    }

    private function forgetRuntimeSingletons(): void
    {
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_08_232332_create_tenant_event_receipts_table.php',
        );
    }
}

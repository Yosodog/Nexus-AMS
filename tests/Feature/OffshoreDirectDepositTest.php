<?php

namespace Tests\Feature;

use App\Exceptions\AmbiguousMutationOutcomeException;
use App\Jobs\AssignTaxBracket;
use App\Jobs\FinalizeDirectDepositDisenrollment;
use App\Models\Account;
use App\Models\Alliance;
use App\Models\DirectDepositEnrollment;
use App\Models\Nation;
use App\Models\Offshore;
use App\Services\AllianceMembershipService;
use App\Services\DirectDepositService;
use App\Services\OffshoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class OffshoreDirectDepositTest extends TestCase
{
    use RefreshDatabase;

    public function test_alliance_change_clears_configuration_and_queues_each_enrollment_once(): void
    {
        Queue::fake();

        $oldAlliance = Alliance::factory()->create();
        $newAlliance = Alliance::factory()->create();
        $offshore = $this->createEnabledOffshore($oldAlliance);
        $first = $this->createEnrollment($offshore);
        $second = $this->createEnrollment($offshore);

        $result = $this->offshoreService()->update($offshore, [
            'alliance_id' => $newAlliance->id,
            'direct_deposit_enabled' => true,
            'direct_deposit_tax_id' => 999,
            'direct_deposit_fallback_tax_id' => 998,
        ]);

        $this->assertTrue($result->allianceIdChanged);
        $this->assertSame(2, $result->queuedDisenrollments);
        $this->assertFalse($result->offshore->direct_deposit_enabled);
        $this->assertNull($result->offshore->direct_deposit_tax_id);
        $this->assertNull($result->offshore->direct_deposit_fallback_tax_id);
        $this->assertNotNull($first->fresh()->disenrollment_requested_at);
        $this->assertNotNull($second->fresh()->disenrollment_requested_at);

        Queue::assertPushed(FinalizeDirectDepositDisenrollment::class, 2);

        $repeat = $this->offshoreService()->update($result->offshore, [
            'alliance_id' => $newAlliance->id,
        ]);

        $this->assertSame(0, $repeat->queuedDisenrollments);
        Queue::assertPushed(FinalizeDirectDepositDisenrollment::class, 2);
    }

    public function test_offshore_member_enrollment_snapshots_configuration_and_queues_assignment(): void
    {
        Queue::fake();

        $offshore = $this->createEnabledOffshore(Alliance::factory()->create());
        $nation = Nation::factory()->create([
            'alliance_id' => $offshore->alliance_id,
            'tax_id' => 300,
        ]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Deposit';
        $account->save();

        app(DirectDepositService::class)->enroll($nation, $account);

        $this->assertDatabaseHas('direct_deposit_enrollments', [
            'nation_id' => $nation->id,
            'offshore_id' => $offshore->id,
            'alliance_id' => $offshore->alliance_id,
            'direct_deposit_tax_id' => 401,
            'fallback_tax_id' => 402,
            'previous_tax_id' => 300,
        ]);
        Queue::assertPushed(AssignTaxBracket::class, 1);
    }

    public function test_disabling_direct_deposit_preserves_tax_ids_and_queues_enrollments(): void
    {
        Queue::fake();

        $offshore = $this->createEnabledOffshore(Alliance::factory()->create());
        $enrollment = $this->createEnrollment($offshore);

        $result = $this->offshoreService()->update($offshore, [
            'direct_deposit_enabled' => false,
        ]);

        $this->assertFalse($result->offshore->direct_deposit_enabled);
        $this->assertSame(401, $result->offshore->direct_deposit_tax_id);
        $this->assertSame(402, $result->offshore->direct_deposit_fallback_tax_id);
        $this->assertSame(1, $result->queuedDisenrollments);
        $this->assertNotNull($enrollment->fresh()->disenrollment_requested_at);
        Queue::assertPushed(FinalizeDirectDepositDisenrollment::class, 1);
    }

    public function test_tax_ids_cannot_change_while_enabled_members_are_enrolled(): void
    {
        $offshore = $this->createEnabledOffshore(Alliance::factory()->create());
        $this->createEnrollment($offshore);

        $this->expectException(ValidationException::class);

        $this->offshoreService()->update($offshore, [
            'direct_deposit_tax_id' => 499,
        ]);
    }

    public function test_delete_starts_disenrollment_and_requires_a_retry(): void
    {
        Queue::fake();

        $offshore = $this->createEnabledOffshore(Alliance::factory()->create());
        $enrollment = $this->createEnrollment($offshore);

        $this->assertFalse($this->offshoreService()->delete($offshore));
        $this->assertDatabaseHas('offshores', ['id' => $offshore->id]);
        $this->assertNotNull($enrollment->fresh()->disenrollment_requested_at);
        Queue::assertPushed(FinalizeDirectDepositDisenrollment::class, 1);
    }

    public function test_pending_disenrollment_keeps_a_disabled_offshore_alliance_collectable(): void
    {
        Queue::fake();
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'assignTaxBracket' => [
                        'id' => 300,
                        'tax_rate' => 100,
                        'resource_tax_rate' => 100,
                    ],
                ],
            ]),
        ]);

        $offshore = $this->createEnabledOffshore(Alliance::factory()->create());
        $enrollment = $this->createEnrollment($offshore);
        app(OffshoreService::class)->update($offshore, ['enabled' => false]);

        $membership = app(AllianceMembershipService::class);
        $this->assertTrue($membership->contains($offshore->alliance_id));
        $this->assertSame(str_repeat('a', 20), $membership->getCredentialsForAlliance($offshore->alliance_id)['api_key']);

        (new FinalizeDirectDepositDisenrollment($enrollment->id))->handle();

        $this->assertFalse($membership->contains($offshore->alliance_id));
    }

    public function test_disenrollment_job_uses_offshore_credentials_and_deletes_only_after_restoration(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'assignTaxBracket' => [
                        'id' => 300,
                        'tax_rate' => 100,
                        'resource_tax_rate' => 100,
                    ],
                ],
            ]),
        ]);

        $offshore = $this->createEnabledOffshore(Alliance::factory()->create());
        $enrollment = $this->createEnrollment($offshore);
        $enrollment->forceFill(['disenrollment_requested_at' => now()])->save();

        (new FinalizeDirectDepositDisenrollment($enrollment->id))->handle();

        $this->assertDatabaseMissing('direct_deposit_enrollments', ['id' => $enrollment->id]);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'api_key='.str_repeat('a', 20))
                && $request->hasHeader('X-Bot-Key', 'mutation-secret')
                && str_contains((string) $request['query'], 'id: 300');
        });
    }

    public function test_ambiguous_disenrollment_failure_keeps_the_pending_enrollment(): void
    {
        Http::fake(['*' => Http::response('upstream failure', 500)]);

        $offshore = $this->createEnabledOffshore(Alliance::factory()->create());
        $enrollment = $this->createEnrollment($offshore);
        $enrollment->forceFill(['disenrollment_requested_at' => now()])->save();

        try {
            (new FinalizeDirectDepositDisenrollment($enrollment->id))->handle();
            $this->fail('An ambiguous mutation must be retried by the queue.');
        } catch (AmbiguousMutationOutcomeException) {
            $this->assertDatabaseHas('direct_deposit_enrollments', ['id' => $enrollment->id]);
            $this->assertNotNull($enrollment->fresh()->disenrollment_requested_at);
        }
    }

    private function createEnabledOffshore(Alliance $alliance): Offshore
    {
        return Offshore::query()->create([
            'name' => 'Test Offshore',
            'alliance_id' => $alliance->id,
            'enabled' => true,
            'direct_deposit_enabled' => true,
            'direct_deposit_tax_id' => 401,
            'direct_deposit_fallback_tax_id' => 402,
            'priority' => 1,
            'api_key' => str_repeat('a', 20),
            'mutation_key' => 'mutation-secret',
        ]);
    }

    private function createEnrollment(Offshore $offshore): DirectDepositEnrollment
    {
        $nation = Nation::factory()->create([
            'alliance_id' => $offshore->alliance_id,
            'tax_id' => $offshore->direct_deposit_tax_id,
        ]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Deposit';
        $account->save();

        return DirectDepositEnrollment::query()->create([
            'nation_id' => $nation->id,
            'offshore_id' => $offshore->id,
            'alliance_id' => $offshore->alliance_id,
            'account_id' => $account->id,
            'direct_deposit_tax_id' => $offshore->direct_deposit_tax_id,
            'fallback_tax_id' => $offshore->direct_deposit_fallback_tax_id,
            'previous_tax_id' => 300,
            'enrolled_at' => now(),
        ]);
    }

    private function offshoreService(): OffshoreService
    {
        $membership = Mockery::mock(AllianceMembershipService::class);
        $membership->shouldReceive('refresh')->andReturn(Collection::empty());

        return new OffshoreService($membership, app(DirectDepositService::class));
    }
}

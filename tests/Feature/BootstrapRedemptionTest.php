<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\BootstrapTokenIntrospector;
use App\DataTransferObjects\BootstrapClaims;
use App\Enums\BootstrapRedemptionMode;
use App\Enums\NexusRuntime;
use App\Enums\TenantBootstrapAction;
use App\Enums\TenantCallbackStatus;
use App\Enums\TenantCallbackType;
use App\Exceptions\BootstrapIntrospectionException;
use App\Models\AuditLog;
use App\Models\BootstrapRedemption;
use App\Models\Nation;
use App\Models\Role;
use App\Models\TenantCallbackDelivery;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RuntimeCapabilities;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BootstrapRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/internal/v1/bootstrap/redeem';

    private const TENANT_ID = '01JZ0000000000000000000000';

    private const RELEASE_ID = 'release-bootstrap-test';

    private const ALLIANCE_ID = 12_345;

    private const NATION_ID = 98_765;

    private const CLOUD_USER_ID = '01JZ1111111111111111111111';

    private const PASSWORD = 'Bootstrap-local-password-938!';

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://tenant.example.test',
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'nexus.managed' => true,
            'nexus.tenant_id' => self::TENANT_ID,
            'nexus.release_id' => self::RELEASE_ID,
            'services.pw.alliance_id' => self::ALLIANCE_ID,
        ]);
        $this->forgetRuntimeSingletons();
        $this->travelTo('2026-08-08 22:00:00');

        $this->adminRole = Role::query()->create([
            'name' => 'default admin',
            'protected' => true,
        ]);
        Nation::factory()->create([
            'id' => self::NATION_ID,
            'alliance_id' => self::ALLIANCE_ID,
            'alliance_position' => 'LEADER',
        ]);
    }

    public function test_hosted_bootstrap_creates_local_admin_and_atomic_evidence(): void
    {
        Queue::fake();
        $token = $this->token('a');
        $tokenHash = hash('sha256', $token);
        $introspectionCalls = 0;
        $this->fakeIntrospector(function (string $receivedHash) use (
            &$introspectionCalls,
            $token,
            $tokenHash,
        ): BootstrapClaims {
            $introspectionCalls++;
            $this->assertSame($tokenHash, $receivedHash);
            $this->assertNull(request()->input('bootstrap_token'));
            $this->assertStringNotContainsString($token, request()->getContent());
            $this->assertSame('POST', request()->method());
            $this->assertSame('api.internal.bootstrap.redeem', request()->route()?->getName());

            return $this->claims();
        });

        $response = $this->postJson(self::ENDPOINT, $this->payload($token))
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson([
                'redemption_id' => 1,
                'local_user_id' => 1,
                'mode' => BootstrapRedemptionMode::Created->value,
            ])
            ->assertJsonMissing(['cloud_user_id' => self::CLOUD_USER_ID]);

        $this->assertSame(1, $introspectionCalls);
        $user = User::query()->without('roles')->sole();
        $this->assertSame('TenantOwner', $user->name);
        $this->assertSame('owner@example.test', $user->email);
        $this->assertSame(self::NATION_ID, (int) $user->nation_id);
        $this->assertTrue((bool) $user->is_admin);
        $this->assertFalse((bool) $user->disabled);
        $this->assertNotNull($user->verified_at);
        $this->assertNull($user->verification_code);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertTrue($user->roles()->whereKey($this->adminRole->getKey())->exists());
        $this->assertFalse(Schema::hasColumn('users', 'cloud_user_id'));

        $redemption = BootstrapRedemption::query()->sole();
        $this->assertSame($tokenHash, $redemption->getRawOriginal('token_hash'));
        $this->assertSame(self::CLOUD_USER_ID, $redemption->cloud_user_id);
        $this->assertSame($user->getKey(), $redemption->local_user_id);
        $this->assertSame(BootstrapRedemptionMode::Created, $redemption->mode);
        $this->assertNotNull($redemption->redeemed_at);

        $delivery = TenantCallbackDelivery::query()->sole();
        $this->assertSame(TenantCallbackType::BootstrapRedeemed, $delivery->event_type);
        $this->assertSame(TenantCallbackStatus::Pending, $delivery->status);
        $this->assertSame(0, $delivery->attempt_count);
        $this->assertEquals([
            'bootstrap_redemption_id' => $redemption->getKey(),
            'cloud_user_id' => self::CLOUD_USER_ID,
            'local_user_id' => $user->getKey(),
            'mode' => BootstrapRedemptionMode::Created->value,
            'nation_id' => self::NATION_ID,
        ], $delivery->payload);

        $audit = AuditLog::query()->sole();
        $this->assertSame('cloud_user', $audit->actor_type);
        $this->assertNull($audit->actor_id);
        $this->assertSame(self::CLOUD_USER_ID, $audit->actor_name);
        $this->assertSame('bootstrap.initial_admin_redeemed', $audit->action);
        $this->assertSame((string) $user->getKey(), $audit->subject_id);
        $this->assertEquals([
            'request' => [
                'channel' => 'tenant-control-bootstrap',
            ],
            'bootstrap_redemption_id' => $redemption->getKey(),
            'tenant_id' => self::TENANT_ID,
            'release_id' => self::RELEASE_ID,
            'nation_id' => self::NATION_ID,
            'local_user_id' => $user->getKey(),
            'mode' => BootstrapRedemptionMode::Created->value,
        ], $audit->context);

        $serializedEvidence = json_encode([
            $response->json(),
            $redemption->toArray(),
            $delivery->toArray(),
            $audit->toArray(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($token, $serializedEvidence);
        $this->assertStringNotContainsString(self::PASSWORD, $serializedEvidence);
        $this->assertNull(app('request')->input('bootstrap_token'));
        $this->assertNull(app('request')->input('password'));
        Queue::assertNothingPushed();
    }

    public function test_existing_local_user_is_linked_only_with_exact_local_credentials(): void
    {
        $passwordHash = Hash::make(self::PASSWORD);
        $user = User::factory()->unverified()->create([
            'name' => 'TenantOwner',
            'email' => 'OWNER@example.test',
            'password' => $passwordHash,
            'nation_id' => self::NATION_ID,
            'is_admin' => false,
            'disabled' => false,
        ]);
        $this->fakeIntrospector(fn (): BootstrapClaims => $this->claims());

        $this->postJson(self::ENDPOINT, $this->payload($this->token('b')))
            ->assertOk()
            ->assertJson([
                'local_user_id' => $user->getKey(),
                'mode' => BootstrapRedemptionMode::Linked->value,
            ]);

        $user->refresh();
        $this->assertSame(1, User::query()->count());
        $this->assertSame($passwordHash, $user->password);
        $this->assertTrue((bool) $user->is_admin);
        $this->assertNotNull($user->verified_at);
        $this->assertTrue($user->roles()->whereKey($this->adminRole->getKey())->exists());
        $this->assertSame(
            BootstrapRedemptionMode::Linked,
            BootstrapRedemption::query()->sole()->mode,
        );
    }

    #[DataProvider('identityMismatchProvider')]
    public function test_existing_user_link_rejects_mismatched_or_disabled_identity(
        array $userOverrides,
        array $requestOverrides,
    ): void {
        $user = User::factory()->create(array_replace([
            'name' => 'TenantOwner',
            'email' => 'owner@example.test',
            'password' => Hash::make(self::PASSWORD),
            'nation_id' => self::NATION_ID,
            'is_admin' => false,
            'disabled' => false,
        ], $userOverrides));
        $this->fakeIntrospector(fn (): BootstrapClaims => $this->claims());

        $this->postJson(
            self::ENDPOINT,
            array_replace($this->payload($this->token('c')), $requestOverrides),
        )
            ->assertConflict()
            ->assertJson([
                'message' => 'Bootstrap could not be completed.',
                'retryable' => false,
            ]);

        $user->refresh();
        $this->assertFalse((bool) $user->is_admin);
        $this->assertFalse($user->roles()->whereKey($this->adminRole->getKey())->exists());
        $this->assertSame(0, BootstrapRedemption::query()->count());
        $this->assertSame(0, TenantCallbackDelivery::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_token_and_initial_admin_action_are_each_single_use(): void
    {
        $this->fakeIntrospector(fn (): BootstrapClaims => $this->claims());
        $firstToken = $this->token('d');

        $this->postJson(self::ENDPOINT, $this->payload($firstToken))->assertCreated();
        $this->postJson(self::ENDPOINT, $this->payload($firstToken))->assertConflict();
        $this->postJson(
            self::ENDPOINT,
            $this->payload($this->token('e')),
        )->assertConflict();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, BootstrapRedemption::query()->count());
        $this->assertSame(1, TenantCallbackDelivery::query()->count());
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_authority_loss_rolls_back_every_local_side_effect(): void
    {
        Nation::query()->whereKey(self::NATION_ID)->update(['alliance_position' => 'HEIR']);
        $this->fakeIntrospector(fn (): BootstrapClaims => $this->claims());

        $this->postJson(self::ENDPOINT, $this->payload($this->token('f')))
            ->assertForbidden();

        $this->assertNoBootstrapSideEffects();
    }

    public function test_claimed_alliance_must_match_runtime_configuration(): void
    {
        $this->fakeIntrospector(fn (): BootstrapClaims => $this->claims(allianceId: 77_777));

        $this->postJson(self::ENDPOINT, $this->payload($this->token('1')))
            ->assertForbidden();

        $this->assertNoBootstrapSideEffects();
    }

    public function test_a_different_existing_administrator_blocks_bootstrap(): void
    {
        $existingAdmin = User::factory()->admin()->create();
        $existingAdmin->roles()->attach($this->adminRole);
        $this->fakeIntrospector(fn (): BootstrapClaims => $this->claims());

        $this->postJson(self::ENDPOINT, $this->payload($this->token('2')))
            ->assertConflict();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, BootstrapRedemption::query()->count());
        $this->assertSame(0, TenantCallbackDelivery::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_missing_or_unprotected_admin_role_rolls_back_bootstrap(): void
    {
        $this->adminRole->update(['protected' => false]);
        $this->fakeIntrospector(fn (): BootstrapClaims => $this->claims());

        $this->postJson(self::ENDPOINT, $this->payload($this->token('3')))
            ->assertStatus(503);

        $this->assertNoBootstrapSideEffects();
    }

    public function test_audit_failure_rolls_back_user_role_redemption_and_callback(): void
    {
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('simulated audit persistence failure'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->fakeIntrospector(fn (): BootstrapClaims => $this->claims());
        $this->withoutExceptionHandling();

        try {
            $this->postJson(self::ENDPOINT, $this->payload($this->token('4')));
            $this->fail('The simulated audit failure did not escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit persistence failure', $exception->getMessage());
        }

        $this->assertNoBootstrapSideEffects();
    }

    #[DataProvider('introspectionFailureProvider')]
    public function test_introspection_failure_never_mutates_local_state(
        bool $retryable,
        int $status,
    ): void {
        $this->fakeIntrospector(static function () use ($retryable, $status): never {
            throw new BootstrapIntrospectionException(
                errorCode: 'simulated_introspection_failure',
                retryable: $retryable,
                httpStatus: $status,
            );
        });

        $response = $this->postJson(self::ENDPOINT, $this->payload($this->token('5')))
            ->assertStatus($status)
            ->assertJsonPath('retryable', $retryable);
        $this->assertStringNotContainsString(
            'simulated_introspection_failure',
            $response->getContent(),
        );
        $this->assertNoBootstrapSideEffects();
    }

    public function test_invalid_token_is_scrubbed_and_rejected_before_introspection(): void
    {
        $calls = 0;
        $this->fakeIntrospector(function () use (&$calls): BootstrapClaims {
            $calls++;

            return $this->claims();
        });
        $invalidToken = 'raw-invalid-token-that-must-not-survive';

        $response = $this->postJson(self::ENDPOINT, $this->payload($invalidToken))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bootstrap_token_valid');

        $this->assertSame(0, $calls);
        $this->assertStringNotContainsString($invalidToken, $response->getContent());
        $this->assertNull(app('request')->input('bootstrap_token'));
        $this->assertStringNotContainsString($invalidToken, app('request')->getContent());
        $this->assertNoBootstrapSideEffects();
    }

    public function test_query_token_smuggling_is_rejected_and_removed_from_url_state(): void
    {
        $calls = 0;
        $this->fakeIntrospector(function () use (&$calls): BootstrapClaims {
            $calls++;

            return $this->claims();
        });
        $queryToken = $this->token('9');

        $response = $this->postJson(
            self::ENDPOINT.'?bootstrap_token='.urlencode($queryToken).'&source=cloud',
            $this->payload($this->token('a')),
        )->assertUnprocessable();

        $this->assertSame(0, $calls);
        $this->assertStringNotContainsString($queryToken, $response->getContent());
        $this->assertStringNotContainsString($queryToken, app('request')->fullUrl());
        $this->assertNull(app('request')->query('source'));
        $this->assertNull(app('request')->query('bootstrap_token'));
        $this->assertNoBootstrapSideEffects();
    }

    public function test_header_token_smuggling_is_rejected_and_removed_from_header_state(): void
    {
        $calls = 0;
        $this->fakeIntrospector(function () use (&$calls): BootstrapClaims {
            $calls++;

            return $this->claims();
        });
        $headerToken = $this->token('a');

        $response = $this
            ->withHeader('X-Nexus-Bootstrap-Token', $headerToken)
            ->postJson(self::ENDPOINT, $this->payload($this->token('b')))
            ->assertUnprocessable();

        $this->assertSame(0, $calls);
        $this->assertStringNotContainsString($headerToken, $response->getContent());
        $this->assertNull(app('request')->header('X-Nexus-Bootstrap-Token'));
        $this->assertNoBootstrapSideEffects();
    }

    public function test_cloud_authentication_fields_are_rejected_and_removed_before_introspection(): void
    {
        $calls = 0;
        $this->fakeIntrospector(function () use (&$calls): BootstrapClaims {
            $calls++;

            return $this->claims();
        });
        $cloudFactor = 'cloud-totp-secret-must-remain-in-cloud';
        $payload = [
            ...$this->payload($this->token('c')),
            'two_factor_secret' => $cloudFactor,
        ];

        $response = $this->postJson(self::ENDPOINT, $payload)->assertUnprocessable();

        $this->assertSame(0, $calls);
        $this->assertNull(app('request')->input('two_factor_secret'));
        $this->assertStringNotContainsString($cloudFactor, app('request')->getContent());
        $this->assertStringNotContainsString($cloudFactor, $response->getContent());
        $this->assertNoBootstrapSideEffects();
    }

    public function test_standalone_and_insecure_managed_urls_hide_the_endpoint(): void
    {
        $calls = 0;
        $this->fakeIntrospector(function () use (&$calls): BootstrapClaims {
            $calls++;

            return $this->claims();
        });

        config(['nexus.runtime' => NexusRuntime::Standalone->value]);
        $this->forgetRuntimeSingletons();
        $this->postJson(self::ENDPOINT, $this->payload($this->token('6')))->assertNotFound();

        config([
            'nexus.runtime' => NexusRuntime::HostedTenant->value,
            'app.url' => 'http://tenant.example.test',
        ]);
        $this->forgetRuntimeSingletons();
        $this->postJson(self::ENDPOINT, $this->payload($this->token('7')))->assertNotFound();

        $this->assertSame(0, $calls);
        $this->assertNoBootstrapSideEffects();
    }

    public function test_bootstrap_endpoint_is_bounded_per_token_before_controller_execution(): void
    {
        $calls = 0;
        $this->fakeIntrospector(function () use (&$calls): BootstrapClaims {
            $calls++;

            return $this->claims();
        });
        $payload = $this->payload($this->token('8'));

        $this->postJson(self::ENDPOINT, $payload)->assertCreated();

        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $this->postJson(self::ENDPOINT, $payload)->assertConflict();
        }

        $this->postJson(self::ENDPOINT, $payload)->assertTooManyRequests();
        $this->assertSame(5, $calls);
    }

    public function test_redemption_model_rejects_claim_mutation(): void
    {
        $redemption = BootstrapRedemption::factory()->create();
        $redemption->tenant_id = '01JZ9999999999999999999999';

        $this->expectException(LogicException::class);
        $redemption->save();
    }

    public function test_bootstrap_migration_is_resumable_and_rejects_incomplete_state(): void
    {
        Schema::drop('bootstrap_redemptions');
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasTable('bootstrap_redemptions'));
        $this->assertBootstrapIndexContract();

        Schema::drop('bootstrap_redemptions');
        Schema::create('bootstrap_redemptions', function (Blueprint $table): void {
            $table->id();
        });

        $this->expectException(LogicException::class);
        $migration->up();
    }

    public function test_bootstrap_migration_rejects_an_incompatible_unique_lookup_index(): void
    {
        Schema::table('bootstrap_redemptions', function (Blueprint $table): void {
            $table->dropIndex(['cloud_user_id']);
            $table->unique('cloud_user_id');
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cloud-user index must not be unique');
        $this->migration()->up();
    }

    public function test_bootstrap_migration_supports_rollback_and_forward_recovery(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasTable('bootstrap_redemptions'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('bootstrap_redemptions'));
        $this->assertBootstrapIndexContract();
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function identityMismatchProvider(): iterable
    {
        yield 'username mismatch' => [[], ['name' => 'DifferentOwner']];
        yield 'email mismatch' => [[], ['email' => 'different@example.test']];
        yield 'password mismatch' => [[], [
            'password' => 'Different-local-password-938!',
            'password_confirmation' => 'Different-local-password-938!',
        ]];
        yield 'nation mismatch' => [['nation_id' => self::NATION_ID + 1], []];
        yield 'disabled account' => [['disabled' => true], []];
    }

    /** @return iterable<string, array{bool, int}> */
    public static function introspectionFailureProvider(): iterable
    {
        yield 'denied' => [false, 403];
        yield 'temporarily unavailable' => [true, 503];
    }

    /** @return array<string, string> */
    private function payload(string $token): array
    {
        return [
            'bootstrap_token' => $token,
            'name' => 'TenantOwner',
            'email' => 'owner@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    private function token(string $character): string
    {
        return 'nxb_'.str_repeat($character, 64);
    }

    private function claims(
        int $allianceId = self::ALLIANCE_ID,
        int $nationId = self::NATION_ID,
    ): BootstrapClaims {
        return new BootstrapClaims(
            tenantId: self::TENANT_ID,
            cloudUserId: self::CLOUD_USER_ID,
            action: TenantBootstrapAction::InitialAdmin,
            releaseId: self::RELEASE_ID,
            allianceId: $allianceId,
            nationId: $nationId,
            issuedAt: CarbonImmutable::parse('2026-08-08 21:59:00 UTC'),
            expiresAt: CarbonImmutable::parse('2026-08-08 22:10:00 UTC'),
            claimsDigest: hash('sha256', 'signed-bootstrap-claims'),
        );
    }

    /** @param callable(string): BootstrapClaims $resolver */
    private function fakeIntrospector(callable $resolver): void
    {
        $closure = Closure::fromCallable($resolver);
        $this->app->instance(
            BootstrapTokenIntrospector::class,
            new class($closure) implements BootstrapTokenIntrospector
            {
                public function __construct(private readonly Closure $resolver) {}

                public function introspect(string $tokenHash): BootstrapClaims
                {
                    return ($this->resolver)($tokenHash);
                }
            },
        );
    }

    private function assertNoBootstrapSideEffects(): void
    {
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, BootstrapRedemption::query()->count());
        $this->assertSame(0, TenantCallbackDelivery::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, Schema::getConnection()->table('role_user')->count());
    }

    private function forgetRuntimeSingletons(): void
    {
        $this->app->forgetInstance(RuntimeCapabilities::class);
        $this->app->forgetInstance(NexusRuntime::class);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_08_223010_create_bootstrap_redemptions_table.php',
        );
    }

    private function assertBootstrapIndexContract(): void
    {
        $indexes = collect(Schema::getIndexes('bootstrap_redemptions'));

        $this->assertTrue($indexes->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === ['token_hash']
                && ($index['unique'] ?? false) === true,
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === ['tenant_id', 'action']
                && ($index['unique'] ?? false) === true,
        ));

        foreach ([['cloud_user_id'], ['local_user_id']] as $columns) {
            $this->assertTrue($indexes->contains(
                fn (array $index): bool => ($index['columns'] ?? []) === $columns
                    && ($index['unique'] ?? false) === false,
            ));
        }
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\MainBankCredential;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\MainBankCredentialService;
use App\Services\PWMessageService;
use App\Services\QueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class MainBankCredentialManagementTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_offshore_permissions_control_main_bank_credential_management(): void
    {
        $viewer = $this->createAdmin(['view-offshores']);

        $this->actingAs($viewer)
            ->get(route('admin.offshores.index'))
            ->assertOk()
            ->assertDontSee('Save Credentials');

        $this->actingAs($viewer)
            ->put(route('admin.offshores.main-bank.credentials.update'), [
                'api_key' => str_repeat('a', 20),
                'mutation_key' => 'viewer-mutation-key',
            ])
            ->assertForbidden();

        $manager = $this->createAdmin(['view-offshores', 'manage-offshores'], 980002);

        $this->actingAs($manager)
            ->get(route('admin.offshores.index'))
            ->assertOk()
            ->assertSee('Save Credentials');
    }

    public function test_manager_can_store_encrypted_main_bank_credentials(): void
    {
        $manager = $this->createAdmin(['view-offshores', 'manage-offshores']);
        $apiKey = str_repeat('a', 20);
        $mutationKey = 's3cr3t-mutation-value';

        $this->actingAs($manager)
            ->put(route('admin.offshores.main-bank.credentials.update'), [
                'api_key' => $apiKey,
                'mutation_key' => $mutationKey,
            ])
            ->assertRedirect(route('admin.offshores.index'))
            ->assertSessionHas('alert-type', 'success');

        $credential = MainBankCredential::query()->findOrFail(MainBankCredential::SINGLETON_ID);

        $this->assertNotSame($apiKey, $credential->getRawOriginal('api_key'));
        $this->assertNotSame($mutationKey, $credential->getRawOriginal('mutation_key'));
        $this->assertSame($apiKey, $credential->api_key_decrypted);
        $this->assertSame($mutationKey, $credential->mutation_key_decrypted);

        $audit = AuditLog::query()->where('action', 'main_bank_credentials_updated')->firstOrFail();
        $auditPayload = json_encode($audit->context, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($apiKey, $auditPayload);
        $this->assertStringNotContainsString($mutationKey, $auditPayload);
        $this->assertSame(['updated' => true], $audit->context['changes']['api_key']);
        $this->assertSame(['updated' => true], $audit->context['changes']['mutation_key']);

        $this->actingAs($manager)
            ->get(route('admin.offshores.index'))
            ->assertOk()
            ->assertSee('encrypted Nexus storage')
            ->assertDontSee($apiKey)
            ->assertDontSee($mutationKey);
    }

    public function test_partial_update_preserves_the_other_main_bank_credential(): void
    {
        $manager = $this->createAdmin(['manage-offshores']);
        $service = app(MainBankCredentialService::class);
        $originalMutationKey = 'original-mutation-key';

        $service->update([
            'api_key' => str_repeat('a', 20),
            'mutation_key' => $originalMutationKey,
        ]);

        $this->actingAs($manager)
            ->put(route('admin.offshores.main-bank.credentials.update'), [
                'api_key' => str_repeat('b', 20),
                'mutation_key' => '',
            ])
            ->assertRedirect(route('admin.offshores.index'));

        $credential = MainBankCredential::query()->findOrFail(MainBankCredential::SINGLETON_ID);

        $this->assertSame(str_repeat('b', 20), $credential->api_key_decrypted);
        $this->assertSame($originalMutationKey, $credential->mutation_key_decrypted);
        $this->assertSame(1, MainBankCredential::query()->count());
    }

    public function test_validation_rejects_invalid_or_empty_updates_without_flashing_secrets(): void
    {
        $manager = $this->createAdmin(['view-offshores', 'manage-offshores']);

        $this->actingAs($manager)
            ->from(route('admin.offshores.index'))
            ->put(route('admin.offshores.main-bank.credentials.update'), [
                'modal_context' => 'main-bank-credentials',
                'api_key' => 'short-secret',
                'mutation_key' => '',
            ])
            ->assertRedirect(route('admin.offshores.index'))
            ->assertSessionHasErrors(['api_key']);

        $this->assertNull(session()->getOldInput('api_key'));
        $this->assertNull(session()->getOldInput('mutation_key'));
        $this->assertDatabaseCount('main_bank_credentials', 0);

        $this->actingAs($manager)
            ->get(route('admin.offshores.index'))
            ->assertOk()
            ->assertSee('The API key must be exactly 20 characters.')
            ->assertDontSee('short-secret');

        $this->actingAs($manager)
            ->from(route('admin.offshores.index'))
            ->put(route('admin.offshores.main-bank.credentials.update'), [
                'modal_context' => 'main-bank-credentials',
                'api_key' => '',
                'mutation_key' => '',
            ])
            ->assertSessionHasErrors(['api_key', 'mutation_key']);
    }

    public function test_stored_credentials_override_environment_values_for_primary_consumers(): void
    {
        config()->set('services.pw.alliance_id', 980100);
        config()->set('services.pw.api_key', 'environment-api-key');
        config()->set('services.pw.mutation_key', 'environment-mutation-key');

        $fallback = new MainBankCredentialService;
        $this->assertSame('environment-api-key', $fallback->apiKey());
        $this->assertSame('environment-mutation-key', $fallback->mutationKey());
        $this->assertSame('environment', $fallback->status()['api_key_source']);

        $apiKey = str_repeat('c', 20);
        $mutationKey = 'database-mutation-key';
        $credentials = new MainBankCredentialService;
        $credentials->update([
            'api_key' => $apiKey,
            'mutation_key' => $mutationKey,
        ]);

        $queryService = new class(null, null, new MainBankCredentialService) extends QueryService
        {
            /** @return array{api_key: string, mutation_key: string|null} */
            public function resolvedCredentials(): array
            {
                return [
                    'api_key' => $this->apiKey(),
                    'mutation_key' => $this->mutationKey(),
                ];
            }
        };

        $this->assertSame([
            'api_key' => $apiKey,
            'mutation_key' => $mutationKey,
        ], $queryService->resolvedCredentials());

        $membership = app(AllianceMembershipService::class);
        $this->assertSame([
            'api_key' => $apiKey,
            'mutation_key' => $mutationKey,
        ], $membership->getCredentialsForAlliance(980100));

        Http::fake([
            'https://politicsandwar.com/api/send-message/' => Http::response(['success' => true]),
        ]);

        $this->assertTrue((new PWMessageService(new MainBankCredentialService))->sendMessage(
            980101,
            'Credential test',
            'Credential test message',
        ));

        Http::assertSent(fn ($request): bool => $request['key'] === $apiKey);

        $status = (new MainBankCredentialService)->status();
        $this->assertSame('database', $status['api_key_source']);
        $this->assertSame('database', $status['mutation_key_source']);
    }

    /** @param array<int, string> $permissions */
    private function createAdmin(array $permissions, int $nationId = 980001): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => $nationId]);
        $this->attachDiscordAccount($admin, ['discord_id' => (string) ($nationId + 1_000_000)]);

        return $this->grantPermissions($admin, $permissions);
    }
}

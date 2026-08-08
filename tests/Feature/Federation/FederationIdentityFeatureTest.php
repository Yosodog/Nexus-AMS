<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Services\FederationIdentityService;
use App\Models\FederationIdentity;
use App\Models\FederationIdentityKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FederationIdentityFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://nexus-one.example');
        config()->set('federation.enabled', true);
        config()->set('federation.features.inbound', true);
        config()->set('federation.features.linking', true);
    }

    public function test_identity_is_generated_once_and_private_keys_are_encrypted_at_rest(): void
    {
        $service = app(FederationIdentityService::class);
        $first = $service->enable();
        $second = $service->enable();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FederationIdentity::query()->count());
        $this->assertSame(1, FederationIdentityKey::query()->count());

        $key = FederationIdentityKey::query()->firstOrFail();
        $raw = DB::table((new FederationIdentityKey)->getTable())->where('id', $key->id)->first();
        $this->assertNotSame($key->signing_private_key, $raw->signing_private_key);
        $this->assertNotSame($key->box_private_key, $raw->box_private_key);
        $this->assertNotEmpty($key->signing_private_key);
        $this->assertNotEmpty($key->box_private_key);
    }

    public function test_discovery_is_minimal_and_only_available_while_enabled(): void
    {
        $service = app(FederationIdentityService::class);
        $identity = $service->enable();
        $key = $identity->activeKey;

        $response = $this->getJson('/.well-known/nexus-federation');
        $response->assertOk()
            ->assertJsonPath('installation_id', $identity->id)
            ->assertJsonPath('origin', 'https://nexus-one.example')
            ->assertJsonPath('current_key.key_id', $key->id)
            ->assertJsonMissingPath('coalitions')
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('capabilities')
            ->assertJsonMissingPath('current_key.signing_private_key')
            ->assertHeader('Cache-Control', 'no-store, private');

        $service->disable();
        $this->getJson('/.well-known/nexus-federation')->assertNotFound();
        $this->assertSame(1, FederationIdentityKey::query()->count());
    }

    public function test_hard_configuration_gate_hides_discovery(): void
    {
        app(FederationIdentityService::class)->enable();
        config()->set('federation.enabled', false);

        $this->getJson('/.well-known/nexus-federation')->assertNotFound();
    }
}

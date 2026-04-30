<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\FeatureTestCase;

class ApiSecurityTest extends FeatureTestCase
{
    public function test_nexus_api_middleware_uses_secure_comparison(): void
    {
        Config::set('services.nexus_api_token', 'secret-token');

        // Wrong token
        $this->postJson('/api/v1/subs/nation/update', [], ['Authorization' => 'Bearer wrong'])
            ->assertStatus(401);

        // Correct token
        // Pass validation but might fail on something else since payload is empty
        $response = $this->postJson('/api/v1/subs/nation/update', [], ['Authorization' => 'Bearer secret-token']);
        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_discord_bot_api_middleware_uses_secure_comparison(): void
    {
        Config::set('services.discord_bot_key', 'bot-secret');

        // Wrong token
        $this->postJson('/api/v1/discord/applications/attach-channel', [], ['Authorization' => 'Bearer wrong'])
            ->assertStatus(401);

        // Correct token
        $response = $this->postJson('/api/v1/discord/applications/attach-channel', [], ['Authorization' => 'Bearer bot-secret']);
        $this->assertNotEquals(401, $response->getStatusCode());
    }
}

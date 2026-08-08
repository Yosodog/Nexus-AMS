<?php

namespace Tests\Feature\API;

use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordAlertRendererManifestApiTest extends TestCase
{
    use SignsDiscordInteractions;

    private const BOT_TOKEN = 'discord-alert-manifest-test-token';

    private const GUILD_ID = '123456789012345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDiscordInteractionSigning();
        config([
            'services.discord_bot_key' => self::BOT_TOKEN,
            'services.discord.guild_id' => self::GUILD_ID,
        ]);
    }

    public function test_manifest_requires_the_signed_service_action(): void
    {
        $this->withToken(self::BOT_TOKEN)
            ->getJson('/api/v1/discord/alerts/manifest')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_discord_relay_proof');
    }

    public function test_manifest_exposes_the_exact_active_renderer_contract(): void
    {
        $response = $this->withHeaders($this->signedDiscordServiceHeaders(
            self::BOT_TOKEN,
            self::GUILD_ID,
            'alerts.manifest',
        ))->getJson('/api/v1/discord/alerts/manifest');

        $response->assertOk()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('data.contract_version', 1)
            ->assertJsonPath('data.capabilities.queue_lanes', true)
            ->assertJsonStructure([
                'data' => [
                    'templates' => [[
                        'template_key',
                        'version',
                        'event_keys',
                        'active',
                    ]],
                ],
            ]);

        $eventKeys = collect($response->json('data.templates'))
            ->flatMap(fn (array $template): array => $template['event_keys'])
            ->unique();

        $this->assertFalse($eventKeys->contains(
            fn (string $eventKey): bool => str_contains($eventKey, 'war_assignment')
                || str_contains($eventKey, 'spy_assignment'),
        ));
    }
}

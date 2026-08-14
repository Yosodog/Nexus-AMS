<?php

namespace Tests\Unit;

use App\Enums\DiscordQueueAction;
use PHPUnit\Framework\TestCase;

class DiscordDeploymentContractTest extends TestCase
{
    public function test_environment_example_advertises_the_complete_relay_v2_contract(): void
    {
        $environment = $this->environmentExample();

        $this->assertSame('2', $environment['DISCORD_RELAY_PROTOCOL_VERSION']);
        $this->assertSame('relay-current', $environment['DISCORD_RELAY_CURRENT_KEY_ID']);
        $this->assertSame('false', $environment['DISCORD_RELAY_V1_READER_ENABLED']);
        $this->assertSame([
            'relay.proof.v2',
            'queue.leases.v1',
            'queue.connection-context.v1',
            'status.provider-diagnostics.v1',
        ], explode(',', $environment['DISCORD_CAPABILITIES']));
        $this->assertSame(
            array_map(
                static fn (DiscordQueueAction $action): string => $action->value,
                DiscordQueueAction::cases(),
            ),
            explode(',', $environment['DISCORD_SUPPORTED_QUEUE_ACTIONS']),
        );
        $this->assertArrayNotHasKey('DISCORD_RELAY_PUBLIC_KEY', $environment);
        $this->assertArrayNotHasKey('DISCORD_LEGACY_UNSIGNED_QUEUE_ENABLED', $environment);
    }

    /** @return array<string, string> */
    private function environmentExample(): array
    {
        $environment = [];
        $lines = file(dirname(__DIR__, 2).'/.env.example', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($lines);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $environment[$key] = $value;
        }

        return $environment;
    }
}

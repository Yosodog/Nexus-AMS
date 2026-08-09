<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Services\Discord\DiscordActorContext;
use App\Services\Discord\DiscordActorContextService;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordMemberSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberContextController extends Controller
{
    use DiscordApiResponses;

    public function __construct(
        private readonly DiscordActorContextService $contexts,
        private readonly DiscordMemberSummaryService $summaries,
    ) {}

    public function context(Request $request): JsonResponse
    {
        [$connection, $context] = $this->resolve($request);

        return $this->discordData([
            ...$context->safePayload(),
            'installation' => [
                'connection_id' => $connection->connectionId,
                'generation' => $connection->generation,
                'application_id' => $connection->applicationId,
                'guild_id' => $connection->guildId,
                'capability_revision' => $connection->capabilityVersion,
            ],
        ], meta: $this->metadata($request, $connection));
    }

    public function summary(Request $request): JsonResponse
    {
        [$connection, $context] = $this->resolve($request);
        $checkedAt = now()->toIso8601String();
        $summary = $this->summaries->summarize(
            $context,
            $connection->capabilityVersion,
            [
                'state' => 'unknown',
                'label' => 'Discord profile synchronization has not been checked yet.',
                'checked_at' => $checkedAt,
                'issues' => [],
            ],
        );

        return $this->discordData($summary, meta: $this->metadata($request, $connection));
    }

    /** @return array{DiscordConnectionContext, DiscordActorContext} */
    private function resolve(Request $request): array
    {
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);
        abort_unless($connection instanceof DiscordConnectionContext, 503, 'Discord connection context is unavailable.');

        return [
            $connection,
            $this->contexts->resolve((string) $request->attributes->get(VerifyDiscordInteraction::USER_ATTRIBUTE)),
        ];
    }

    /** @return array<string, mixed> */
    private function metadata(Request $request, DiscordConnectionContext $connection): array
    {
        return [
            'capability_revision' => $connection->capabilityVersion,
            'connection_id' => $connection->connectionId,
            'connection_generation' => $connection->generation,
            'discord_application_id' => $connection->applicationId,
            'guild_id' => $connection->guildId,
            'generated_at' => now()->toIso8601String(),
            'correlation_id' => (string) $request->attributes->get(VerifyDiscordInteraction::INTERACTION_ATTRIBUTE),
            'idempotent_replay' => false,
        ];
    }
}

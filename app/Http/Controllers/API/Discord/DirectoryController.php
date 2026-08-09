<?php

namespace App\Http\Controllers\API\Discord;

use App\Http\Controllers\API\Discord\Concerns\DiscordApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Http\Requests\Discord\DiscordDirectorySearchRequest;
use App\Models\DiscordAccount;
use App\Models\User;
use App\Services\Discord\DiscordConnectionContext;
use App\Services\Discord\DiscordDirectoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DirectoryController extends Controller
{
    use DiscordApiResponses;

    public function discordUser(Request $request, string $discordUserId, DiscordDirectoryService $directory): JsonResponse
    {
        abort_unless(preg_match('/^\d{17,20}$/', $discordUserId) === 1, 422, 'A valid Discord user ID is required.');
        [$actor, $account, $connection] = $this->context($request);
        try {
            $identity = $directory->discordUser($actor, $account, $discordUserId);
        } catch (AuthorizationException) {
            return $this->discordError(
                'forbidden',
                'You do not have permission to view another Nexus member.',
                403,
                ['retryable' => false],
                $this->metadata($request, $connection),
            );
        }

        return $this->discordData(
            $identity,
            meta: $this->metadata($request, $connection),
        );
    }

    public function nations(DiscordDirectorySearchRequest $request, DiscordDirectoryService $directory): JsonResponse
    {
        [, , $connection] = $this->context($request);

        return $this->discordData([
            'items' => $directory->searchNations($request->string('query')->toString()),
        ], meta: $this->metadata($request, $connection));
    }

    public function nation(Request $request, int $nation, DiscordDirectoryService $directory): JsonResponse
    {
        [, , $connection] = $this->context($request);

        return $this->discordData(
            $directory->nation($nation),
            meta: $this->metadata($request, $connection),
        );
    }

    public function alliances(DiscordDirectorySearchRequest $request, DiscordDirectoryService $directory): JsonResponse
    {
        [, , $connection] = $this->context($request);

        return $this->discordData([
            'items' => $directory->searchAlliances($request->string('query')->toString()),
        ], meta: $this->metadata($request, $connection));
    }

    public function alliance(Request $request, int $alliance, DiscordDirectoryService $directory): JsonResponse
    {
        [, , $connection] = $this->context($request);

        return $this->discordData(
            $directory->alliance($alliance),
            meta: $this->metadata($request, $connection),
        );
    }

    /** @return array{User, DiscordAccount, DiscordConnectionContext} */
    private function context(Request $request): array
    {
        $actor = $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);
        $account = $request->attributes->get(ResolveDiscordActor::ACCOUNT_ATTRIBUTE);
        $connection = $request->attributes->get(VerifyDiscordInteraction::CONNECTION_ATTRIBUTE);
        abort_unless($actor instanceof User, 503, 'Discord actor context is unavailable.');
        abort_unless($account instanceof DiscordAccount, 503, 'Discord account context is unavailable.');
        abort_unless($connection instanceof DiscordConnectionContext, 503, 'Discord connection context is unavailable.');

        return [$actor, $account, $connection];
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

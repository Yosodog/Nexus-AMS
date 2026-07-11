<?php

namespace App\Http\Middleware;

use App\Models\DiscordAccount;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ResolveDiscordActor
{
    public const ACTOR_ATTRIBUTE = 'discord_actor';

    public const ACCOUNT_ATTRIBUTE = 'discord_account';

    public const GUILD_HEADER = 'X-Discord-Guild-ID';

    public const USER_HEADER = 'X-Discord-User-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $configuredGuildId = trim((string) config('services.discord.guild_id'));

        if ($configuredGuildId === '') {
            return $this->error('discord_guild_not_configured', 'Discord guild validation is not configured.', 503);
        }

        $guildId = trim((string) $request->header(self::GUILD_HEADER));
        $discordUserId = trim((string) $request->header(self::USER_HEADER));

        if (! $this->isSnowflake($guildId) || ! hash_equals($configuredGuildId, $guildId)) {
            return $this->error('invalid_discord_guild', 'The Discord guild is not authorized.', 403);
        }

        if (! $this->isSnowflake($discordUserId)) {
            return $this->error('invalid_discord_actor', 'A valid Discord actor is required.', 401);
        }

        $accounts = DiscordAccount::query()
            ->with('user')
            ->where('discord_id', $discordUserId)
            ->whereNull('unlinked_at')
            ->whereHas('user', fn ($query) => $query
                ->where('disabled', false)
                ->whereNotNull('verified_at')
                ->whereNotNull('nation_id'))
            ->limit(2)
            ->get()
            ->values();

        if ($accounts->count() !== 1) {
            $error = $accounts->isEmpty() ? 'discord_actor_not_linked' : 'discord_actor_ambiguous';
            $message = $accounts->isEmpty()
                ? 'The Discord account is not actively linked to Nexus.'
                : 'The Discord account has multiple active Nexus links.';

            return $this->error($error, $message, 403);
        }

        $discordAccount = $accounts->first();

        if (! $discordAccount->user->nation_id) {
            return $this->error('discord_actor_has_no_nation', 'The linked Nexus user has no nation.', 403);
        }

        $request->attributes->set(self::ACTOR_ATTRIBUTE, $discordAccount->user);
        $request->attributes->set(self::ACCOUNT_ATTRIBUTE, $discordAccount);

        try {
            return $next($request);
        } catch (ValidationException $exception) {
            return $this->error('validation_error', 'The request failed validation.', 422, $exception->errors());
        } catch (AuthorizationException $exception) {
            return $this->error('forbidden', 'You do not have permission to perform this action.', 403);
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();

            return $this->error(
                $status === 404 ? 'not_found' : ($status === 403 ? 'forbidden' : 'request_rejected'),
                $exception->getMessage() ?: 'Nexus rejected the request.',
                $status,
            );
        }
    }

    private function isSnowflake(string $value): bool
    {
        return preg_match('/^\d{1,20}$/', $value) === 1;
    }

    private function error(string $error, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $error,
                'message' => $message,
                ...($details === [] ? [] : ['details' => $details]),
            ],
            'meta' => ['contract_version' => 1],
        ], $status);
    }
}

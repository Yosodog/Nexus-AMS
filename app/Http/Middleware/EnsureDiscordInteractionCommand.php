<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDiscordInteractionCommand
{
    public function handle(Request $request, Closure $next, string ...$allowedCommands): Response
    {
        $protocol = (int) $request->attributes->get(VerifyDiscordInteraction::PROTOCOL_ATTRIBUTE, 1);
        $command = (string) $request->attributes->get(
            $protocol >= 2
                ? VerifyDiscordInteraction::ACTION_ATTRIBUTE
                : VerifyDiscordInteraction::COMMAND_ATTRIBUTE,
            '',
        );

        if ($command === '' || ! in_array($command, $allowedCommands, true)) {
            return response()->json([
                'error' => [
                    'code' => 'discord_interaction_action_mismatch',
                    'message' => 'The signed Discord interaction is not authorized for this action.',
                ],
                'meta' => ['contract_version' => $protocol >= 2 ? 2 : 1],
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\DiscordAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscordVerificationController extends Controller
{
    /**
     * Link a Discord account to a user by validating a verification token.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'discord_id' => ['required', 'string'],
            'discord_username' => ['required', 'string'],
        ]);

        $discordAccount = DiscordAccountService::verifyWithToken(
            $validated['token'],
            $validated['discord_id'],
            $validated['discord_username']
        );

        if (! $discordAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification token.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'user_id' => $discordAccount->user_id,
            'discord_id' => $discordAccount->discord_id,
            'discord_username' => $discordAccount->discord_username,
            'linked_at' => optional($discordAccount->linked_at)->toIso8601String(),
        ]);
    }
}
